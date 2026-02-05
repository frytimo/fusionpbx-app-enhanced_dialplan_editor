<?php

/*
 * FusionPBX
 * Version: MPL 1.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is FusionPBX
 *
 * The Initial Developer of the Original Code is
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Portions created by the Initial Developer are Copyright (C) 2008-2026
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 * Mark J Crane <markjcrane@fusionpbx.com>
 */

// includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

// check permissions
if (!permission_exists('dialplan_edit') && !permission_exists('dialplan_add')) {
	echo "access denied";
	exit;
}

// add multi-lingual support
$language = new text;
$text = $language->get();

// get the list of applications from FreeSWITCH
if (empty($_SESSION['switch']['applications']) || !is_array($_SESSION['switch']['applications'])) {
	$esl = event_socket::create();
	if ($esl->is_connected()) {
		$result = event_socket::api('show application');
		$show_applications = explode("\n\n", $result);
		$raw_applications = explode("\n", $show_applications[0]);
		unset($result, $esl);

		$applications = [];
		$previous_application = null;
		foreach ($raw_applications as $row) {
			if (!empty($row)) {
				$application_array = explode(",", $row);
				$application = $application_array[0];
				if (
					$application != "name" &&
					$application != "system" &&
					$application != "bgsystem" &&
					$application != "spawn" &&
					$application != "bg_spawn" &&
					$application != "spawn_stream" &&
					stristr($application, "[") != true
				) {
					if ($application != $previous_application) {
						$applications[] = $application;
					}
				}
				$previous_application = $application;
			}
		}
		$_SESSION['switch']['applications'] = $applications;
	} else {
		$_SESSION['switch']['applications'] = [];
	}
}
$applications = $_SESSION['switch']['applications'];

// get the domain_uuid from the PHP session
$domain_uuid = $_SESSION['domain_uuid'];
$domain_name = $_SESSION['domain_name'];

// get the uuids from request
$dialplan_uuid = '';
$app_uuid = '';
if (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) {
	$dialplan_uuid = $_REQUEST['id'];
}
if (!empty($_REQUEST['app_uuid']) && is_uuid($_REQUEST['app_uuid'])) {
	$app_uuid = $_REQUEST['app_uuid'];
}

// set the action
$action = !empty($dialplan_uuid) ? 'update' : 'add';

// get user preferences for XML panel visibility
$xml_panel_visible = true;
if (!empty($_SESSION['user_settings']['dialplan_editor_xml_visible']['text'])) {
	$xml_panel_visible = $_SESSION['user_settings']['dialplan_editor_xml_visible']['text'] === 'true';
}

// handle AJAX requests
if (!empty($_POST['ajax_action'])) {
	// validate the token
	$token = new token;
	if (!$token->validate($_SERVER['PHP_SELF'])) {
		header('Content-Type: application/json');
		echo json_encode(['success' => false, 'message' => $text['message-invalid_token']]);
		exit;
	}

	switch ($_POST['ajax_action']) {
		case 'save_xml_visibility':
			// save user preference for XML panel visibility
			$visible = $_POST['visible'] === 'true' ? 'true' : 'false';
			$array['user_settings'][0]['user_setting_uuid'] = uuid();
			$array['user_settings'][0]['user_uuid'] = $_SESSION['user_uuid'];
			$array['user_settings'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
			$array['user_settings'][0]['user_setting_category'] = 'dialplan_editor';
			$array['user_settings'][0]['user_setting_subcategory'] = 'xml_visible';
			$array['user_settings'][0]['user_setting_name'] = 'text';
			$array['user_settings'][0]['user_setting_value'] = $visible;
			$array['user_settings'][0]['user_setting_enabled'] = 'true';

			$p = permissions::new();
			$p->add('user_setting_add', 'temp');
			$p->add('user_setting_edit', 'temp');
			$database->save($array, false);
			$p->delete('user_setting_add', 'temp');
			$p->delete('user_setting_edit', 'temp');

			$_SESSION['user_settings']['dialplan_editor_xml_visible']['text'] = $visible;

			header('Content-Type: application/json');
			echo json_encode(['success' => true]);
			exit;
	}
}

// process the HTTP POST for saving
if (!empty($_POST['dialplan_xml']) && !empty($_POST['submit'])) {
	// validate the token
	$token = new token;
	if (!$token->validate($_SERVER['PHP_SELF'])) {
		message::add($text['message-invalid_token'], 'negative');
		header('Location: dialplans.php');
		exit;
	}

	// get the posted data
	$dialplan_xml = $_POST['dialplan_xml'];
	$dialplan_name = $_POST['dialplan_name'] ?? '';
	$dialplan_number = $_POST['dialplan_number'] ?? '';
	$dialplan_context = $_POST['dialplan_context'] ?? $domain_name;
	$dialplan_continue = $_POST['dialplan_continue'] ?? 'false';
	$dialplan_order = $_POST['dialplan_order'] ?? '200';
	$dialplan_enabled = $_POST['dialplan_enabled'] ?? 'true';
	$dialplan_description = $_POST['dialplan_description'] ?? '';
	$dialplan_destination = $_POST['dialplan_destination'] ?? 'false';

	// sanitize the xml - check for dangerous patterns
	$dialplan_valid = true;
	$dangerous_patterns = [
		"/.*([\"\'])system([\"\']).*>/i",
		"/.*([\"\'])bgsystem([\"\']).*>/i",
		"/.*([\"\'])bg_spawn([\"\']).*>/i",
		"/.*([\"\'])spawn([\"\']).*>/i",
		"/.*([\"\'])spawn_stream([\"\']).*>/i",
		"/.*{system.*/i",
		"/.*{bgsystem.*/i",
		"/.*{bg_spawn.*/i",
		"/.*{spawn.*/i",
		"/.*{spawn_stream.*/i"
	];
	foreach ($dangerous_patterns as $pattern) {
		if (preg_match($pattern, $dialplan_xml)) {
			$dialplan_valid = false;
			break;
		}
	}

	if (!$dialplan_valid) {
		message::add($text['message-invalid_xml'] ?? 'XML contains invalid or dangerous content.', 'negative');
		header('Location: dialplan_edit_unified.php?id=' . urlencode($dialplan_uuid) . (!empty($app_uuid) ? '&app_uuid=' . urlencode($app_uuid) : ''));
		exit;
	}

	// check for required fields
	if (empty($dialplan_name)) {
		message::add($text['message-required'] . $text['label-name'], 'negative');
		header('Location: dialplan_edit_unified.php?id=' . urlencode($dialplan_uuid) . (!empty($app_uuid) ? '&app_uuid=' . urlencode($app_uuid) : ''));
		exit;
	}

	// build the save array
	$array['dialplans'][0]['dialplan_uuid'] = !empty($dialplan_uuid) ? $dialplan_uuid : uuid();
	$array['dialplans'][0]['domain_uuid'] = permission_exists('dialplan_domain') && !empty($_POST['domain_uuid']) && is_uuid($_POST['domain_uuid'])
		? $_POST['domain_uuid']
		: $domain_uuid;
	if ($action === 'add') {
		$array['dialplans'][0]['app_uuid'] = uuid();
	}
	$array['dialplans'][0]['dialplan_name'] = $dialplan_name;
	$array['dialplans'][0]['dialplan_number'] = $dialplan_number;
	$array['dialplans'][0]['dialplan_context'] = $dialplan_context;
	$array['dialplans'][0]['dialplan_continue'] = $dialplan_continue;
	$array['dialplans'][0]['dialplan_order'] = $dialplan_order;
	$array['dialplans'][0]['dialplan_enabled'] = $dialplan_enabled;
	$array['dialplans'][0]['dialplan_description'] = $dialplan_description;
	$array['dialplans'][0]['dialplan_destination'] = $dialplan_destination;
	$array['dialplans'][0]['dialplan_xml'] = $dialplan_xml;
	$array['dialplans'][0]['dialplan_editor_version'] = 'unified';

	// save to database
	$database->save($array);
	unset($array);

	// update the dialplan_uuid for new records
	if ($action === 'add') {
		$dialplan_uuid = $array['dialplans'][0]['dialplan_uuid'];
	}

	// clear the cache
	$cache = new cache;
	if ($dialplan_context == "\${domain_name}" || $dialplan_context == "global") {
		$cache->delete("dialplan:*");
	} else {
		$cache->delete("dialplan:" . $dialplan_context);
	}

	// clear destinations session
	if (isset($_SESSION['destinations']['array'])) {
		unset($_SESSION['destinations']['array']);
	}

	// set message
	if ($action === 'add') {
		message::add($text['message-add']);
	} else {
		message::add($text['message-update']);
	}

	// redirect
	header('Location: dialplan_edit_unified.php?id=' . urlencode($dialplan_uuid) . (!empty($app_uuid) ? '&app_uuid=' . urlencode($app_uuid) : ''));
	exit;
}

// get existing dialplan data
$dialplan_xml = '';
$dialplan_name = '';
$dialplan_number = '';
$dialplan_context = $domain_name;
$dialplan_continue = 'false';
$dialplan_order = '200';
$dialplan_enabled = 'true';
$dialplan_description = '';
$dialplan_destination = 'false';
$dialplan_editor_version = '';
$hostname = '';

if ($action === 'update' && !empty($dialplan_uuid)) {
	$sql = "SELECT * FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
	$parameters['dialplan_uuid'] = $dialplan_uuid;
	$row = $database->select($sql, $parameters, 'row');
	if (is_array($row) && sizeof($row) > 0) {
		$dialplan_xml = $row['dialplan_xml'] ?? '';
		$dialplan_name = $row['dialplan_name'] ?? '';
		$dialplan_number = $row['dialplan_number'] ?? '';
		$dialplan_context = $row['dialplan_context'] ?? $domain_name;
		$dialplan_continue = $row['dialplan_continue'] ? 'true' : 'false';
		$dialplan_order = $row['dialplan_order'] ?? '200';
		$dialplan_enabled = $row['dialplan_enabled'] ? 'true' : 'false';
		$dialplan_description = $row['dialplan_description'] ?? '';
		$dialplan_destination = $row['dialplan_destination'] ? 'true' : 'false';
		$dialplan_editor_version = $row['dialplan_editor_version'] ?? '';
		$hostname = $row['hostname'] ?? '';
		$app_uuid = $row['app_uuid'] ?? $app_uuid;
		$dialplan_domain_uuid = $row['domain_uuid'] ?? $domain_uuid;
	}
	unset($sql, $parameters, $row);
}

// determine if this is a migration (legacy to unified)
$is_migration = ($action === 'update' && $dialplan_editor_version !== 'unified');

// create token
$object = new token;
$token = $object->create($_SERVER['PHP_SELF']);

// get theme and editor settings using settings object
$settings = new settings(['domain_uuid' => $domain_uuid, 'user_uuid' => $_SESSION['user_uuid'] ?? null]);

// editor settings
$setting_size = !empty($settings->get('editor', 'font_size')) ? $settings->get('editor', 'font_size') : '12px';
$setting_theme = !empty($settings->get('editor', 'theme')) ? $settings->get('editor', 'theme') : 'cobalt';
$setting_invisibles = $settings->get('editor', 'invisibles', 'false');
$setting_indenting = $settings->get('editor', 'indent_guides', 'false');
$setting_numbering = $settings->get('editor', 'line_numbers', 'true');

// button theme settings for drag zone styling
$button_background_color = $settings->get('theme', 'button_background_color', '#4f4f4f');
$button_background_color_bottom = $settings->get('theme', 'button_background_color_bottom', '#000000');
$button_background_color_hover = $settings->get('theme', 'button_background_color_hover', '#000000');
$button_background_color_bottom_hover = $settings->get('theme', 'button_background_color_bottom_hover', '#000000');
$button_border_size = $settings->get('theme', 'button_border_size', '1px');
$button_border_color = $settings->get('theme', 'button_border_color', '#242424');
$button_border_color_hover = $settings->get('theme', 'button_border_color_hover', '#000000');
$button_text_font = $settings->get('theme', 'button_text_font', 'Candara, Calibri, Segoe, "Segoe UI", Optima, Arial, sans-serif');
$button_text_color = $settings->get('theme', 'button_text_color', '#ffffff');
$button_text_weight = $settings->get('theme', 'button_text_weight', 'bold');
$button_text_size = $settings->get('theme', 'button_text_size', '11px');

// dialplan node type colors (for theming)
$node_color_condition = $settings->get('dialplan', 'node_color_condition', '#2196F3');
$node_color_condition_hover = $settings->get('dialplan', 'node_color_condition_hover', '#1976D2');
$node_color_action = $settings->get('dialplan', 'node_color_action', '#4CAF50');
$node_color_action_hover = $settings->get('dialplan', 'node_color_action_hover', '#388E3C');
$node_color_anti_action = $settings->get('dialplan', 'node_color_anti_action', '#FF9800');
$node_color_anti_action_hover = $settings->get('dialplan', 'node_color_anti_action_hover', '#F57C00');
$node_color_regex = $settings->get('dialplan', 'node_color_regex', '#9C27B0');
$node_color_regex_hover = $settings->get('dialplan', 'node_color_regex_hover', '#7B1FA2');

// toggle switch colors (for theming)
$toggle_color_enabled = $settings->get('dialplan', 'toggle_color_enabled', '#2e82d0');
$toggle_color_disabled = $settings->get('dialplan', 'toggle_color_disabled', '#cccccc');
$toggle_handle_color = $settings->get('dialplan', 'toggle_handle_color', '#ffffff');

// show the header
$document['title'] = $text['title-dialplan_edit'] . ' - ' . ($text['label-unified_editor'] ?? 'Visual Editor');
require_once "resources/header.php";

?>

<style>
:root {
	--dialplan-editor-breakpoint: 1024px;
}

.dialplan-editor-container {
	display: flex;
	gap: 0;
	position: relative;
}

.dialplan-visual-panel {
	flex: 1;
	min-width: 0;
	position: relative;
	transition: flex 0.3s ease;
	padding: 10px;
}

.dialplan-xml-panel {
	width: 40%;
	min-width: 200px;
	max-width: 80%;
	display: flex;
	flex-direction: column;
	border-left: 1px solid var(--border-color, #ccc);
	position: relative;
}

.dialplan-xml-panel.collapsed {
	width: 0 !important;
	min-width: 0 !important;
	overflow: hidden;
	border-left: none;
}

/* Resize handle for XML panel - modern grip style with 6 dots */
.xml-resize-handle {
	position: absolute;
	top: 0;
	left: 0;
	width: 12px;
	height: 100%;
	cursor: col-resize;
	background: var(--border-color, #ddd);
	z-index: 15;
	display: flex;
	align-items: center;
	justify-content: center;
}

.xml-resize-handle::before {
	content: '';
	width: 6px;
	height: 24px;
	background-image: radial-gradient(circle, var(--text-color, #666) 1.5px, transparent 1.5px);
	background-size: 4px 8px;
	background-position: center;
	opacity: 0.6;
}

.xml-resize-handle:hover::before,
.xml-resize-handle.dragging::before {
	opacity: 1;
}

/* Toggle button - only visible when panel is collapsed */
.dialplan-panel-toggle {
	position: sticky;
	top: 100px;
	align-self: flex-start;
	z-index: 10;
	background: var(--button-background-color, #f0f0f0);
	border: 1px solid var(--border-color, #ccc);
	border-radius: 4px;
	padding: 8px 4px;
	cursor: pointer;
	margin-left: -12px;
	margin-right: -12px;
	display: none;
}

.dialplan-panel-toggle.visible {
	display: block;
}

.dialplan-panel-toggle:hover {
	background: var(--button-background-color-hover, #e0e0e0);
}

#editor {
	height: 500px;
}

/* Stale/Error overlay */
.dialplan-ui-overlay {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(255, 255, 255, 0.85);
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	z-index: 100;
	backdrop-filter: blur(2px);
}

.dialplan-ui-overlay.hidden {
	display: none;
}

.dialplan-ui-overlay .overlay-icon {
	font-size: 48px;
	margin-bottom: 16px;
}

.dialplan-ui-overlay .overlay-message {
	font-size: 16px;
	margin-bottom: 16px;
	text-align: center;
	max-width: 80%;
}

.dialplan-ui-overlay.stale .overlay-icon {
	color: #f0ad4e;
}

.dialplan-ui-overlay.error .overlay-icon {
	color: #d9534f;
}

/* Sync status indicator */
.sync-status {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border-radius: 4px;
	font-size: 12px;
	margin-left: 15px;
}

.sync-status.synced {
	background: #dff0d8;
	color: #3c763d;
}

.sync-status.stale {
	background: #fcf8e3;
	color: #8a6d3b;
}

.sync-status.error {
	background: #f2dede;
	color: #a94442;
}

/* Visual tree nodes - compact layout */
.dialplan-node {
	margin: 4px 0;
	padding: 6px 8px 6px 32px;
	border: 1px solid var(--border-color, #ddd);
	border-radius: 4px;
	background: var(--card-background-color, #fff);
	position: relative;
	min-height: 36px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.dialplan-node.extension {
	padding-left: 8px;
	border-left: 1px solid var(--border-color, #ddd);
}

.dialplan-node.condition,
.dialplan-node.action,
.dialplan-node.anti-action,
.dialplan-node.regex {
	border-left: none;
}

/* Left border drag zone with rotated label - base styling */
.dialplan-node-drag-zone {
	position: absolute;
	left: 0;
	top: 0;
	bottom: 0;
	width: 28px;
	cursor: grab;
	display: flex;
	align-items: center;
	justify-content: center;
	user-select: none;
	border-radius: 3px 0 0 3px;
	background: linear-gradient(to bottom,
		<?php echo $button_background_color ?? '#4f4f4f'; ?>,
		<?php echo $button_background_color_bottom ?? '#000000'; ?>
	);
	border: <?php echo $button_border_size ?? '1px'; ?> solid <?php echo $button_border_color ?? '#242424'; ?>;
	border-right: none;
}

.dialplan-node-drag-zone:hover {
	background: linear-gradient(to bottom,
		<?php echo $button_background_color_hover ?? '#000000'; ?>,
		<?php echo $button_background_color_bottom_hover ?? '#000000'; ?>
	);
	border-color: <?php echo $button_border_color_hover ?? '#000000'; ?>;
}

/* Node type specific colors */
.dialplan-node-drag-zone.type-condition {
	background: linear-gradient(to bottom,
		<?php echo $node_color_condition; ?>,
		<?php echo $node_color_condition; ?>cc
	);
	border-color: <?php echo $node_color_condition; ?>;
}

.dialplan-node-drag-zone.type-condition:hover {
	background: linear-gradient(to bottom,
		<?php echo $node_color_condition_hover; ?>,
		<?php echo $node_color_condition_hover; ?>cc
	);
	border-color: <?php echo $node_color_condition_hover; ?>;
}

.dialplan-node-drag-zone.type-action {
	background: linear-gradient(to bottom,
		<?php echo $node_color_action; ?>,
		<?php echo $node_color_action; ?>cc
	);
	border-color: <?php echo $node_color_action; ?>;
}

.dialplan-node-drag-zone.type-action:hover {
	background: linear-gradient(to bottom,
		<?php echo $node_color_action_hover; ?>,
		<?php echo $node_color_action_hover; ?>cc
	);
	border-color: <?php echo $node_color_action_hover; ?>;
}

.dialplan-node-drag-zone.type-anti-action {
	background: linear-gradient(to bottom,
		<?php echo $node_color_anti_action; ?>,
		<?php echo $node_color_anti_action; ?>cc
	);
	border-color: <?php echo $node_color_anti_action; ?>;
}

.dialplan-node-drag-zone.type-anti-action:hover {
	background: linear-gradient(to bottom,
		<?php echo $node_color_anti_action_hover; ?>,
		<?php echo $node_color_anti_action_hover; ?>cc
	);
	border-color: <?php echo $node_color_anti_action_hover; ?>;
}

.dialplan-node-drag-zone.type-regex {
	background: linear-gradient(to bottom,
		<?php echo $node_color_regex; ?>,
		<?php echo $node_color_regex; ?>cc
	);
	border-color: <?php echo $node_color_regex; ?>;
}

.dialplan-node-drag-zone.type-regex:hover {
	background: linear-gradient(to bottom,
		<?php echo $node_color_regex_hover; ?>,
		<?php echo $node_color_regex_hover; ?>cc
	);
	border-color: <?php echo $node_color_regex_hover; ?>;
}

/* Regex condition (condition with regex="all" etc.) - uses regex colors */
.dialplan-node-drag-zone.type-regex-condition {
	background: linear-gradient(to bottom,
		<?php echo $node_color_regex; ?>,
		<?php echo $node_color_regex; ?>cc
	);
	border-color: <?php echo $node_color_regex; ?>;
}

.dialplan-node-drag-zone.type-regex-condition:hover {
	background: linear-gradient(to bottom,
		<?php echo $node_color_regex_hover; ?>,
		<?php echo $node_color_regex_hover; ?>cc
	);
	border-color: <?php echo $node_color_regex_hover; ?>;
}

.dialplan-node-drag-zone:active {
	cursor: grabbing;
}

.dialplan-node-drag-zone .node-type-label {
	writing-mode: vertical-rl;
	text-orientation: mixed;
	transform: rotate(180deg);
	font-family: <?php echo $button_text_font ?? 'Candara, Calibri, Segoe, "Segoe UI", Optima, Arial, sans-serif'; ?>;
	font-size: <?php echo $button_text_size ?? '11px'; ?>;
	font-weight: <?php echo $button_text_weight ?? 'bold'; ?>;
	text-transform: uppercase;
	color: <?php echo $button_text_color ?? '#ffffff'; ?>;
	letter-spacing: 0.5px;
	white-space: nowrap;
	pointer-events: none;
}

/* Remove old header styles */
.dialplan-node-header {
	display: none;
}

.dialplan-node-type {
	display: none;
}

.dialplan-node-children {
	margin-left: 0;
	padding: 4px 4px 4px 8px;
	border-left: 2px solid #eee;
	min-height: 8px;
	margin-top: 4px;
	position: relative;
}

/* Drag and drop styles */
.dialplan-node.dragging {
	opacity: 0.5;
	transform: scale(0.98);
}

.dialplan-node.drag-over {
	border: 2px dashed #5bc0de;
	background: rgba(91, 192, 222, 0.1);
}

.dialplan-node.drag-over-above::before {
	content: '';
	display: block;
	height: 3px;
	background: #5bc0de;
	margin-bottom: 5px;
	border-radius: 2px;
}

.dialplan-node.drag-over-below::after {
	content: '';
	display: block;
	height: 3px;
	background: #5bc0de;
	margin-top: 5px;
	border-radius: 2px;
}

.dialplan-node-children.drag-over {
	background: rgba(91, 192, 222, 0.15);
	border-left-color: #5bc0de;
	border-left-width: 3px;
	outline: 2px dashed #5bc0de;
	outline-offset: -2px;
}

.drop-zone {
	min-height: 30px;
	border: 2px dashed transparent;
	border-radius: 4px;
	margin: 4px 0;
	transition: all 0.2s ease;
}

.drop-zone.drag-over {
	border-color: #5bc0de;
	background: rgba(91, 192, 222, 0.1);
}

.dialplan-node-form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 6px;
	flex: 1;
}

.dialplan-node-form > div {
	display: flex;
	flex-direction: column;
	min-width: 100px;
	flex: 1;
}

.dialplan-node-form > div.field-data {
	flex: 2;
	min-width: 180px;
}

.dialplan-node-form > div.field-enabled {
	flex: 0 0 auto;
	min-width: 50px;
	max-width: 60px;
}

/* Self-contained toggle switch for node enabled/disabled */
.node-toggle-switch {
	position: relative;
	display: inline-block;
	width: 44px;
	height: 24px;
	margin-top: 2px;
}

.node-toggle-switch input {
	opacity: 0;
	width: 0;
	height: 0;
}

.node-toggle-slider {
	position: absolute;
	cursor: pointer;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background-color: <?php echo $toggle_color_disabled; ?>;
	border-radius: 24px;
	transition: 0.3s;
	z-index: 5;
}

.node-toggle-slider:before {
	position: absolute;
	content: '';
	height: 18px;
	width: 18px;
	left: 3px;
	bottom: 3px;
	background-color: <?php echo $toggle_handle_color; ?>;
	border-radius: 50%;
	transition: 0.3s;
}

.node-toggle-switch input:checked + .node-toggle-slider {
	background-color: <?php echo $toggle_color_enabled; ?>;
}

.node-toggle-switch input:checked + .node-toggle-slider:before {
	transform: translateX(20px);
}

/* Property toggle switches (larger version for properties panel) */
.property-toggle-container {
	display: flex;
	align-items: center;
	gap: 10px;
}

.property-toggle-switch {
	position: relative;
	display: inline-block;
	width: 50px;
	height: 26px;
}

.property-toggle-switch input {
	opacity: 0;
	width: 0;
	height: 0;
}

.property-toggle-slider {
	position: absolute;
	cursor: pointer;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background-color: <?php echo $toggle_color_disabled; ?>;
	border-radius: 26px;
	transition: 0.3s;
}

.property-toggle-slider:before {
	position: absolute;
	content: '';
	height: 20px;
	width: 20px;
	left: 3px;
	bottom: 3px;
	background-color: <?php echo $toggle_handle_color; ?>;
	border-radius: 50%;
	transition: 0.3s;
}

.property-toggle-switch input:checked + .property-toggle-slider {
	background-color: <?php echo $toggle_color_enabled; ?>;
}

.property-toggle-switch input:checked + .property-toggle-slider:before {
	transform: translateX(24px);
}

/* Visually hidden but accessible for screen readers */
.property-toggle-label {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

/* Visual editor disabled overlay */
.visual-editor-disabled-overlay {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(128, 128, 128, 0.5);
	z-index: 100;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	color: #fff;
	font-size: 18px;
	text-shadow: 0 1px 3px rgba(0,0,0,0.5);
}

.visual-editor-disabled-overlay.hidden {
	display: none;
}

.visual-editor-disabled-overlay i {
	font-size: 48px;
	margin-bottom: 15px;
	opacity: 0.8;
}

.dialplan-node-form label {
	display: block;
	font-size: 10px;
	color: #888;
	margin-bottom: 1px;
	text-transform: uppercase;
}

.dialplan-node-form input,
.dialplan-node-form select {
	width: 100%;
	height: 28px;
	padding: 2px 6px;
	font-size: 12px;
}

/* Node disabled styling - use filter instead of opacity to avoid cascading to children */
.dialplan-node.node-disabled > .dialplan-node-drag-zone {
	filter: grayscale(70%) opacity(0.6);
}

.dialplan-node.node-disabled > .dialplan-node-content {
	opacity: 0.5;
}

.dialplan-node.node-disabled > .dialplan-node-content .dialplan-node-form input,
.dialplan-node.node-disabled > .dialplan-node-content .dialplan-node-form select {
	background-color: #f5f5f5;
	color: #999;
}

/* Inline content row (form + delete button) */
.dialplan-node-content {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.dialplan-node-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.dialplan-node-actions .btn {
	padding: 4px 8px;
	height: 28px;
	min-width: 28px;
}

/* Add node buttons */
.add-node-buttons {
	margin-left: 8px;
	padding: 2px 0;
}

.add-node-btn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 6px;
	font-size: 11px;
	cursor: pointer;
	border: 1px dashed #ccc;
	background: transparent;
	border-radius: 4px;
	margin: 4px 2px;
}

.add-node-btn:hover {
	background: #f5f5f5;
	border-color: #999;
}

/* Mobile responsive */
@media (max-width: 1024px) {
	.dialplan-editor-container {
		flex-direction: column;
	}

	.dialplan-visual-panel,
	.dialplan-xml-panel {
		width: 100% !important;
		min-width: 100% !important;
	}

	.dialplan-xml-panel {
		border-left: none;
		border-top: 1px solid var(--border-color, #ccc);
	}

	.dialplan-panel-toggle {
		display: none;
	}

	.mobile-panel-tabs {
		display: flex !important;
	}

	.dialplan-visual-panel.mobile-hidden,
	.dialplan-xml-panel.mobile-hidden {
		display: none !important;
	}
}

@media (min-width: 1025px) {
	.mobile-panel-tabs {
		display: none !important;
	}
}

.mobile-panel-tabs {
	display: none;
	border-bottom: 1px solid var(--border-color, #ccc);
	margin-bottom: 10px;
}

.mobile-panel-tabs button {
	flex: 1;
	padding: 10px;
	border: none;
	background: #f5f5f5;
	cursor: pointer;
	font-weight: 500;
}

.mobile-panel-tabs button.active {
	background: var(--button-background-color-active, #0275d8);
	color: white;
}

/* Migration warning */
.migration-warning {
	background: #fcf8e3;
	border: 1px solid #faebcc;
	border-radius: 4px;
	padding: 15px;
	margin-bottom: 15px;
	position: relative;
	cursor: pointer;
}

.migration-warning.dismissed {
	display: none;
}

.migration-warning h4 {
	margin: 0 0 10px 0;
	color: #8a6d3b;
	padding-right: 30px;
}

.migration-warning p {
	margin: 0;
	color: #8a6d3b;
}

.migration-warning .close-btn {
	position: absolute;
	top: 10px;
	right: 10px;
	background: none;
	border: none;
	font-size: 18px;
	color: #8a6d3b;
	cursor: pointer;
	padding: 5px;
	line-height: 1;
	opacity: 0.7;
}

.migration-warning .close-btn:hover {
	opacity: 1;
}

/* Loading spinner */
.dialplan-spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 2px solid #f3f3f3;
	border-top: 2px solid #3498db;
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

/* Collapsible properties panel */
.properties-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	cursor: pointer;
	user-select: none;
	background: linear-gradient(to bottom, #f8f8f8, #e8e8e8);
	border-bottom: 1px solid #ddd;
	border-radius: 4px 4px 0 0;
}

.properties-header:hover {
	background: linear-gradient(to bottom, #e8e8e8, #d8d8d8);
}

.properties-header h3 {
	margin: 0;
	font-size: 13px;
	font-weight: 600;
}

.properties-header .toggle-icon {
	transition: transform 0.2s ease;
}

.properties-header.collapsed .toggle-icon {
	transform: rotate(-90deg);
}

.properties-content {
	max-height: 500px;
	overflow: hidden;
	transition: max-height 0.3s ease, padding 0.3s ease;
}

.properties-content.collapsed {
	max-height: 0;
	padding: 0;
}

.properties-card {
	border: 1px solid #ddd;
	border-radius: 4px;
	margin-bottom: 10px;
}

.properties-card .properties-content table {
	margin: 0;
}

/* Autocomplete wrapper */
.autocomplete-wrapper {
	position: relative;
	flex: 1;
	min-width: 100px;
}

.autocomplete-wrapper input {
	width: 100%;
}

.autocomplete-wrapper input.invalid-application {
	border-color: #d9534f !important;
	box-shadow: 0 0 3px rgba(217, 83, 79, 0.4);
}

.autocomplete-wrapper input.valid-application {
	border-color: #5cb85c !important;
}

.autocomplete-dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	max-height: 200px;
	overflow-y: auto;
	background: white;
	border: 1px solid #ccc;
	border-top: none;
	border-radius: 0 0 4px 4px;
	box-shadow: 0 4px 8px rgba(0,0,0,0.15);
	z-index: 1000;
	display: none;
}

.autocomplete-dropdown.visible {
	display: block;
}

.autocomplete-item {
	padding: 6px 10px;
	cursor: pointer;
	font-size: 12px;
	border-bottom: 1px solid #eee;
}

.autocomplete-item:last-child {
	border-bottom: none;
}

.autocomplete-item:hover,
.autocomplete-item.selected {
	background: #f0f7ff;
}

.autocomplete-item .app-name {
	font-weight: 500;
}

.autocomplete-item .app-description {
	font-size: 10px;
	color: #888;
	margin-top: 2px;
}
</style>

<form method="post" name="frm" id="frm">

<div class="action_bar" id="action_bar">
	<div class="heading"><b><?php echo escape($text['title-dialplan_edit']); ?></b></div>
	<div class="actions">
		<?php
		echo button::create(['type' => 'button', 'label' => $text['button-back'], 'icon' => $settings->get('theme', 'button_icon_back'), 'id' => 'btn_back', 'link' => 'dialplans.php' . (!empty($app_uuid) && is_uuid($app_uuid) ? '?app_uuid=' . urlencode($app_uuid) : '')]);

		if ($action === 'update' && permission_exists('dialplan_xml')) {
			echo button::create(['type' => 'button', 'label' => $text['button-legacy_editor'] ?? 'Legacy Editor', 'icon' => 'history', 'style' => 'margin-left: 15px;', 'link' => 'dialplan_edit.php?id=' . urlencode($dialplan_uuid) . (!empty($app_uuid) ? '&app_uuid=' . urlencode($app_uuid) : '')]);
		}
		?>

		<!-- Sync Status Indicator -->
		<span id="sync-status" class="sync-status synced">
			<i class="fas fa-check-circle"></i>
			<span id="sync-status-text"><?php echo $text['label-in_sync'] ?? 'In sync'; ?></span>
		</span>

		<?php
		echo button::create(['type' => 'button', 'label' => $text['button-visualize'] ?? 'Visualize', 'icon' => 'eye', 'id' => 'btn_visualize', 'style' => 'margin-left: 15px;', 'onclick' => 'visualizeXml();']);
		echo button::create(['type' => 'submit', 'label' => $text['button-save'], 'icon' => $settings->get('theme', 'button_icon_save'), 'id' => 'btn_save', 'name' => 'submit', 'value' => 'save', 'style' => 'margin-left: 15px;']);
		?>
	</div>
	<div style="clear: both;"></div>
</div>

<?php if ($is_migration): ?>
<div class="migration-warning" id="migration-warning" onclick="dismissMigrationWarning();" title="<?php echo $text['label-click_to_dismiss'] ?? 'Click to dismiss'; ?>">
	<button type="button" class="close-btn" title="<?php echo $text['label-dismiss'] ?? 'Dismiss'; ?>"><i class="fas fa-times"></i></button>
	<h4><i class="fas fa-exclamation-triangle"></i> <?php echo $text['label-migration_notice'] ?? 'Migration Notice'; ?></h4>
	<p><?php echo $text['message-migration_warning'] ?? 'This dialplan will be migrated to the unified editor on save. XML will become the only source. Existing detail rows will be preserved for CLI recovery but will no longer be used.'; ?></p>
</div>
<?php endif; ?>

<?php echo $text['description-dialplan-edit-unified'] ?? 'Edit the dialplan using the visual editor. Changes to the visual editor update the XML immediately. To apply XML changes to the visual editor, click Visualize.'; ?>
<br><br>

<!-- Basic Properties Card (Collapsible) -->
<div class="properties-card">
	<div class="properties-header" onclick="togglePropertiesPanel();" id="properties-header">
		<h3><i class="fas fa-cog"></i> <?php echo $text['label-properties'] ?? 'Properties'; ?></h3>
		<i class="fas fa-chevron-down toggle-icon" id="properties-toggle-icon"></i>
	</div>
	<div class="properties-content" id="properties-content">
		<table width="100%" border="0" cellpadding="0" cellspacing="0">
			<tr>
				<td width="50%" style="vertical-align: top;">
					<table width="100%" border="0" cellpadding="0" cellspacing="0">
						<tr>
							<td class="vncellreq" width="30%"><?php echo $text['label-name']; ?></td>
							<td class="vtable" width="70%">
								<input class="formfld" type="text" name="dialplan_name" id="dialplan_name" maxlength="255" value="<?php echo escape($dialplan_name); ?>" required>
							</td>
						</tr>
						<tr>
							<td class="vncell"><?php echo $text['label-number']; ?></td>
							<td class="vtable">
								<input class="formfld" type="text" name="dialplan_number" id="dialplan_number" maxlength="255" value="<?php echo escape($dialplan_number); ?>">
							</td>
						</tr>
						<tr>
							<td class="vncell"><?php echo $text['label-context']; ?></td>
							<td class="vtable">
								<input class="formfld" type="text" name="dialplan_context" id="dialplan_context" maxlength="255" value="<?php echo escape($dialplan_context); ?>">
							</td>
						</tr>
						<tr>
							<td class="vncell"><?php echo $text['label-continue']; ?></td>
							<td class="vtable">
								<div class="property-toggle-container">
									<label class="property-toggle-switch">
										<input type="checkbox" id="dialplan_continue_toggle" <?php echo $dialplan_continue === 'true' ? 'checked' : ''; ?> onchange="updatePropertyToggle('dialplan_continue', this.checked);">
										<span class="property-toggle-slider"></span>
									</label>
									<span class="property-toggle-label" id="dialplan_continue_label"><?php echo $dialplan_continue === 'true' ? ($text['option-true'] ?? 'True') : ($text['option-false'] ?? 'False'); ?></span>
									<input type="hidden" name="dialplan_continue" id="dialplan_continue" value="<?php echo escape($dialplan_continue); ?>">
								</div>
							</td>
						</tr>
					</table>
				</td>
				<td width="50%" style="vertical-align: top;">
					<table width="100%" border="0" cellpadding="0" cellspacing="0">
						<tr>
							<td class="vncellreq" width="30%"><?php echo $text['label-order']; ?></td>
							<td class="vtable" width="70%">
								<select class="formfld" name="dialplan_order" id="dialplan_order">
									<?php for ($i = 0; $i <= 999; $i++): ?>
									<option value="<?php echo str_pad($i, 3, '0', STR_PAD_LEFT); ?>" <?php echo (int) $dialplan_order === $i ? 'selected' : ''; ?>><?php echo str_pad($i, 3, '0', STR_PAD_LEFT); ?></option>
									<?php endfor; ?>
								</select>
							</td>
						</tr>
						<tr>
							<td class="vncell"><?php echo $text['label-destination']; ?></td>
							<td class="vtable">
								<div class="property-toggle-container">
									<label class="property-toggle-switch">
										<input type="checkbox" id="dialplan_destination_toggle" <?php echo $dialplan_destination === 'true' ? 'checked' : ''; ?> onchange="updatePropertyToggle('dialplan_destination', this.checked);">
										<span class="property-toggle-slider"></span>
									</label>
									<span class="property-toggle-label" id="dialplan_destination_label"><?php echo $dialplan_destination === 'true' ? ($text['option-true'] ?? 'True') : ($text['option-false'] ?? 'False'); ?></span>
									<input type="hidden" name="dialplan_destination" id="dialplan_destination" value="<?php echo escape($dialplan_destination); ?>">
								</div>
							</td>
						</tr>
						<tr>
							<td class="vncellreq"><?php echo $text['label-enabled']; ?></td>
							<td class="vtable">
								<div class="property-toggle-container">
									<label class="property-toggle-switch">
										<input type="checkbox" id="dialplan_enabled_toggle" <?php echo $dialplan_enabled === 'true' ? 'checked' : ''; ?> onchange="updateDialplanEnabled(this.checked);">
										<span class="property-toggle-slider"></span>
									</label>
									<span class="property-toggle-label" id="dialplan_enabled_label"><?php echo $dialplan_enabled === 'true' ? ($text['option-true'] ?? 'True') : ($text['option-false'] ?? 'False'); ?></span>
									<input type="hidden" name="dialplan_enabled" id="dialplan_enabled" value="<?php echo escape($dialplan_enabled); ?>">
								</div>
							</td>
						</tr>
						<tr>
							<td class="vncell"><?php echo $text['label-description']; ?></td>
							<td class="vtable">
								<input class="formfld" type="text" name="dialplan_description" id="dialplan_description" maxlength="255" value="<?php echo escape($dialplan_description); ?>">
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
	</div>
</div>

<!-- Mobile Panel Tabs -->
<div class="mobile-panel-tabs">
	<button type="button" id="tab-visual" class="active" onclick="switchMobilePanel('visual');">
		<i class="fas fa-project-diagram"></i> <?php echo $text['label-visual_editor'] ?? 'Visual Editor'; ?>
	</button>
	<button type="button" id="tab-xml" onclick="switchMobilePanel('xml');">
		<i class="fas fa-code"></i> <?php echo $text['label-xml'] ?? 'XML'; ?>
	</button>
</div>

<!-- Editor Container -->
<div class="card">
	<div class="dialplan-editor-container">

		<!-- Visual Panel -->
		<div class="dialplan-visual-panel" id="visual-panel">
			<!-- Disabled Overlay (when dialplan is disabled) -->
			<div class="visual-editor-disabled-overlay <?php echo $dialplan_enabled === 'true' ? 'hidden' : ''; ?>" id="visual-disabled-overlay">
				<i class="fas fa-ban"></i>
				<span><?php echo $text['message-dialplan_disabled'] ?? 'Visual Editor Disabled'; ?></span>
				<small style="margin-top: 10px; opacity: 0.8;"><?php echo $text['message-dialplan_disabled_hint'] ?? 'Enable the dialplan to use the visual editor'; ?></small>
			</div>
			<!-- Stale/Error Overlay -->
			<div class="dialplan-ui-overlay hidden" id="ui-overlay">
				<div class="overlay-icon"><i class="fas fa-sync-alt"></i></div>
				<div class="overlay-message" id="overlay-message">
					<?php echo $text['message-xml_modified'] ?? 'XML modified — click Visualize to update editor'; ?>
				</div>
				<?php echo button::create(['type' => 'button', 'label' => $text['button-visualize'] ?? 'Visualize', 'icon' => 'eye', 'id' => 'btn_visualize_overlay', 'onclick' => 'visualizeXml();']); ?>
			</div>

			<!-- Tree Container -->
			<div id="visual-tree">
				<div class="dialplan-node extension" id="extension-node">
					<div class="dialplan-node-header">
						<span class="dialplan-node-type"><?php echo $text['label-extension'] ?? 'Extension'; ?></span>
					</div>
					<div id="extension-children">
						<!-- Nodes will be rendered here by JavaScript -->
					</div>
					<div class="add-node-buttons">
						<button type="button" class="add-node-btn" onclick="addNode('action');">
							<i class="fas fa-plus"></i> <?php echo $text['option-action'] ?? 'Action'; ?>
						</button>
						<button type="button" class="add-node-btn" onclick="addNode('condition');">
							<i class="fas fa-plus"></i> <?php echo $text['option-condition'] ?? 'Condition'; ?>
						</button>
						<button type="button" class="add-node-btn" onclick="addNode('regex-condition');">
							<i class="fas fa-plus"></i> <?php echo $text['option-regex_condition'] ?? 'Regex'; ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Toggle Button -->
		<button type="button" class="dialplan-panel-toggle <?php echo !$xml_panel_visible ? 'visible' : ''; ?>" id="panel-toggle" onclick="toggleXmlPanel();" title="<?php echo $text['label-toggle_xml'] ?? 'Toggle XML Panel'; ?>">
			<i class="fas <?php echo $xml_panel_visible ? 'fa-chevron-right' : 'fa-chevron-left'; ?>" id="toggle-icon"></i>
		</button>

		<!-- XML Panel -->
		<div class="dialplan-xml-panel <?php echo !$xml_panel_visible ? 'collapsed' : ''; ?>" id="xml-panel">
			<!-- Resize Handle -->
			<div class="xml-resize-handle" id="xml-resize-handle" title="<?php echo $text['label-drag_to_resize'] ?? 'Drag to resize'; ?>"></div>
			<div style="padding: 8px; padding-left: 14px; border-bottom: 1px solid var(--border-color, #ccc); display: flex; align-items: center; justify-content: space-between;">
				<strong><i class="fas fa-code"></i> <?php echo $text['label-xml'] ?? 'XML'; ?></strong>
				<select id="theme" class="formfld" style="width: auto;" onchange="changeTheme();">
					<?php
					$themes = [
						'Light' => ['chrome', 'clouds', 'crimson_editor', 'dawn', 'dreamweaver', 'eclipse', 'github', 'iplastic', 'katzenmilch', 'kuroir', 'solarized_light', 'sqlserver', 'textmate', 'tomorrow', 'xcode'],
						'Dark' => ['ambiance', 'chaos', 'clouds_midnight', 'cobalt', 'dracula', 'gob', 'gruvbox', 'idle_fingers', 'kr_theme', 'merbivore', 'merbivore_soft', 'mono_industrial', 'monokai', 'nord_dark', 'one_dark', 'pastel_on_dark', 'solarized_dark', 'terminal', 'tomorrow_night', 'tomorrow_night_blue', 'tomorrow_night_bright', 'tomorrow_night_eighties', 'twilight', 'vibrant_ink']
					];
					foreach ($themes as $group => $theme_list) {
						echo "<optgroup label='" . escape($group) . "'>";
						foreach ($theme_list as $theme) {
							$selected = $theme === $setting_theme ? 'selected' : '';
							echo "<option value='" . escape($theme) . "' $selected>" . escape(ucwords(str_replace('_', ' ', $theme))) . "</option>";
						}
						echo "</optgroup>";
					}
					?>
				</select>
			</div>
			<div id="editor"></div>
		</div>

	</div>
</div>

<!-- Hidden field for XML content -->
<input type="hidden" name="dialplan_xml" id="dialplan_xml_hidden" value="">
<input type="hidden" name="app_uuid" value="<?php echo escape($app_uuid); ?>">
<?php if ($action === 'update'): ?>
<input type="hidden" name="dialplan_uuid" value="<?php echo escape($dialplan_uuid); ?>">
<?php endif; ?>
<input type="hidden" name="<?php echo $token['name']; ?>" value="<?php echo $token['hash']; ?>">

</form>

<!-- Migration Confirmation Modal -->
<?php if ($is_migration): ?>
<?php echo modal::create([
		'id' => 'modal-migration',
		'type' => 'general',
		'message' => $text['message-migration_confirm'] ?? 'This will migrate the dialplan to the unified editor. XML will become the only source. Continue?',
		'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'style' => 'float: right; margin-left: 15px;', 'onclick' => 'confirmMigration();'])
	]); ?>
<?php endif; ?>

<!-- Save Confirmation Modal for Stale State -->
<?php echo modal::create([
	'id' => 'modal-save-stale',
	'type' => 'general',
	'message' => $text['message-save_stale'] ?? 'Visual editor is not synced with XML. Save anyway?',
	'actions' => button::create(['type' => 'button', 'label' => $text['button-save'], 'icon' => 'check', 'style' => 'float: right; margin-left: 15px;', 'onclick' => 'confirmSave();'])
]); ?>

<!-- Save Confirmation Modal for Error State -->
<?php echo modal::create([
	'id' => 'modal-save-error',
	'type' => 'general',
	'message' => $text['message-save_error'] ?? 'XML has parse errors. Saving may result in a broken dialplan. Save anyway?',
	'actions' => button::create(['type' => 'button', 'label' => $text['button-save'], 'icon' => 'check', 'style' => 'float: right; margin-left: 15px;', 'onclick' => 'confirmSave();'])
]); ?>

<script type="text/javascript" src="<?php echo PROJECT_PATH; ?>/resources/ace/ace.js" charset="utf-8"></script>
<script type="text/javascript" src="<?php echo PROJECT_PATH; ?>/resources/javascript/dialplan_parser.js?v=<?php echo time(); ?>"></script>

<script type="text/javascript">
(function() {
	'use strict';

	// Available applications from FreeSWITCH (for autocomplete validation)
	const availableApplications = <?php echo json_encode($applications ?? []); ?>;

	// Available condition/regex fields (for autocomplete)
	const availableFields = [
		{ value: 'ani', label: 'ANI' },
		{ value: 'ani2', label: 'ANI2' },
		{ value: 'caller_id_name', label: 'Caller ID Name' },
		{ value: 'caller_id_number', label: 'Caller ID Number' },
		{ value: 'chan_name', label: 'Channel Name' },
		{ value: 'context', label: 'Context' },
		{ value: 'destination_number', label: 'Destination Number' },
		{ value: 'dialplan', label: 'Dialplan' },
		{ value: 'network_addr', label: 'Network Address' },
		{ value: 'rdnis', label: 'RDNIS' },
		{ value: 'source', label: 'Source' },
		{ value: 'username', label: 'Username' },
		{ value: 'uuid', label: 'UUID' },
		{ value: '${call_direction}', label: '${call_direction}' },
		{ value: '${number_alias}', label: '${number_alias}' },
		{ value: '${sip_contact_host}', label: '${sip_contact_host}' },
		{ value: '${sip_contact_uri}', label: '${sip_contact_uri}' },
		{ value: '${sip_contact_user}', label: '${sip_contact_user}' },
		{ value: '${sip_h_Diversion}', label: '${sip_h_Diversion}' },
		{ value: '${sip_from_host}', label: '${sip_from_host}' },
		{ value: '${sip_from_uri}', label: '${sip_from_uri}' },
		{ value: '${sip_from_user}', label: '${sip_from_user}' },
		{ value: '${sip_to_uri}', label: '${sip_to_uri}' },
		{ value: '${sip_to_user}', label: '${sip_to_user}' },
		{ value: '${toll_allow}', label: '${toll_allow}' }
	];

	// State management
	let syncState = 'synced'; // 'synced', 'stale', 'error'
	let isDirty = false;
	let tree = null;
	let editor = null;
	let nodeCounter = 0;
	let isMobile = window.matchMedia('(max-width: 1024px)').matches;
	let xmlPanelVisible = <?php echo $xml_panel_visible ? 'true' : 'false'; ?>;
	let skipAceChange = false;
	let propertiesCollapsed = false; // Start expanded so properties are visible

	// Toggle properties panel
	window.togglePropertiesPanel = function() {
		const content = document.getElementById('properties-content');
		const header = document.getElementById('properties-header');
		propertiesCollapsed = !propertiesCollapsed;

		if (propertiesCollapsed) {
			content.classList.add('collapsed');
			header.classList.add('collapsed');
		} else {
			content.classList.remove('collapsed');
			header.classList.remove('collapsed');
		}
	};

	// Update property toggle (for Continue and Destination)
	window.updatePropertyToggle = function(fieldName, isChecked) {
		const hiddenInput = document.getElementById(fieldName);
		const label = document.getElementById(fieldName + '_label');

		if (hiddenInput) {
			hiddenInput.value = isChecked ? 'true' : 'false';
		}
		if (label) {
			label.textContent = isChecked ? '<?php echo $text['option-true'] ?? 'True'; ?>' : '<?php echo $text['option-false'] ?? 'False'; ?>';
		}
	};

	// Update dialplan enabled state - controls visual editor availability
	window.updateDialplanEnabled = function(isChecked) {
		const hiddenInput = document.getElementById('dialplan_enabled');
		const label = document.getElementById('dialplan_enabled_label');
		const overlay = document.getElementById('visual-disabled-overlay');

		if (hiddenInput) {
			hiddenInput.value = isChecked ? 'true' : 'false';
		}
		if (label) {
			label.textContent = isChecked ? '<?php echo $text['option-true'] ?? 'True'; ?>' : '<?php echo $text['option-false'] ?? 'False'; ?>';
		}

		// Show/hide the visual editor disabled overlay
		if (overlay) {
			if (isChecked) {
				overlay.classList.add('hidden');
			} else {
				overlay.classList.remove('hidden');
			}
		}
	};

	// Dismiss migration warning
	window.dismissMigrationWarning = function() {
		const warning = document.getElementById('migration-warning');
		if (warning) {
			warning.classList.add('dismissed');
		}
	};

	// Initialize ACE editor
	function initEditor() {
		editor = ace.edit('editor');
		editor.setOptions({
			mode: 'ace/mode/xml',
			theme: 'ace/theme/' + document.getElementById('theme').value,
			selectionStyle: 'text',
			cursorStyle: 'smooth',
			showInvisibles: <?php echo $setting_invisibles; ?>,
			displayIndentGuides: <?php echo $setting_indenting; ?>,
			showLineNumbers: <?php echo $setting_numbering; ?>,
			showGutter: true,
			scrollPastEnd: true,
			fadeFoldWidgets: true,
			showPrintMargin: false,
			highlightGutterLine: false,
			useSoftTabs: false
		});
		document.getElementById('editor').style.fontSize = '<?php echo $setting_size; ?>';

		// Set initial XML content
		const initialXml = <?php echo json_encode($dialplan_xml); ?>;
		if (initialXml) {
			skipAceChange = true;
			editor.setValue(initialXml, -1);
			skipAceChange = false;
		}

		// Listen for changes
		editor.on('change', function() {
			if (skipAceChange) return;
			isDirty = true;
			setSyncState('stale');
		});

		// Remove certain keyboard shortcuts
		editor.commands.bindKey('Ctrl-T', null);
		editor.commands.bindKey('Ctrl-F', null);
		editor.commands.bindKey('Ctrl-H', null);

		// Parse initial XML to populate tree
		if (initialXml) {
			visualizeXml(true);
		} else {
			// Create empty extension
			tree = {
				type: 'extension',
				attributes: { name: '', continue: 'false', uuid: '' },
				children: []
			};
			renderTree();
		}
	}

	// Set sync state and update UI
	function setSyncState(state, errorMessage) {
		syncState = state;
		const statusEl = document.getElementById('sync-status');
		const statusText = document.getElementById('sync-status-text');
		const overlay = document.getElementById('ui-overlay');
		const overlayMessage = document.getElementById('overlay-message');

		statusEl.className = 'sync-status ' + state;

		switch (state) {
			case 'synced':
				statusEl.innerHTML = '<i class="fas fa-check-circle"></i> <span id="sync-status-text"><?php echo $text['label-in_sync'] ?? 'In sync'; ?></span>';
				overlay.classList.add('hidden');
				overlay.classList.remove('stale', 'error');
				break;
			case 'stale':
				statusEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span id="sync-status-text"><?php echo $text['label-ui_outdated'] ?? 'UI outdated'; ?></span>';
				overlay.classList.remove('hidden', 'error');
				overlay.classList.add('stale');
				overlayMessage.textContent = '<?php echo $text['message-xml_modified'] ?? 'XML modified — click Visualize to update editor'; ?>';
				overlay.querySelector('.overlay-icon').innerHTML = '<i class="fas fa-sync-alt"></i>';
				break;
			case 'error':
				statusEl.innerHTML = '<i class="fas fa-times-circle"></i> <span id="sync-status-text"><?php echo $text['label-parse_error'] ?? 'Parse error'; ?></span>';
				overlay.classList.remove('hidden', 'stale');
				overlay.classList.add('error');
				overlayMessage.textContent = errorMessage || '<?php echo $text['message-parse_error'] ?? 'Unable to visualize — XML has errors'; ?>';
				overlay.querySelector('.overlay-icon').innerHTML = '<i class="fas fa-exclamation-circle"></i>';
				break;
		}
	}

	// Visualize XML (parse and render tree)
	window.visualizeXml = function(initial) {
		const xml = editor.getValue();
		if (!xml.trim()) {
			tree = {
				type: 'extension',
				attributes: { name: document.getElementById('dialplan_name').value || '', continue: 'false', uuid: '' },
				children: []
			};
			renderTree();
			setSyncState('synced');
			return;
		}

		// Show spinner
		const btn = document.getElementById('btn_visualize');
		const originalHtml = btn.innerHTML;
		btn.innerHTML = '<span class="dialplan-spinner"></span> ' + btn.textContent;
		btn.disabled = true;

		// Use setTimeout to allow UI update
		setTimeout(function() {
			const result = DialplanParser.parseXmlToTree(xml);

			btn.innerHTML = originalHtml;
			btn.disabled = false;

			if (result.success) {
				tree = result.tree;
				renderTree();
				setSyncState('synced');

				// On mobile, switch to visual panel after successful visualize
				if (isMobile && !initial) {
					switchMobilePanel('visual');
				}
			} else {
				setSyncState('error', result.error);
			}
		}, 10);
	};

	// Add a node at the root level (extension children)
	function addNode(type) {
		if (!tree) {
			tree = { type: 'extension', attributes: { name: '', continue: 'false' }, children: [] };
		}
		if (!tree.children) tree.children = [];

		// Handle regex-condition as a special type that creates a condition with regex="all"
		const actualType = (type === 'regex-condition') ? 'condition' : type;
		// Only conditions are containers (regex child elements are not)
		const isContainer = (actualType === 'condition');
		const newNode = { type: actualType, attributes: {}, children: isContainer ? [] : undefined, enabled: true };

		if (isContainer) {
			newNode.attributes = { field: '', expression: '', break: '' };
			// If adding a regex condition, set the regex attribute and flag, and add initial regex child
			if (type === 'regex-condition') {
				newNode.attributes.regex = 'all';
				newNode.isRegexCondition = true;
				// Auto-add a regex child since regex conditions require at least one
				newNode.children.push({
					type: 'regex',
					attributes: { field: '', expression: '', break: '' },
					enabled: true
				});
			}
		} else {
			newNode.attributes = { application: '', data: '', inline: '' };
		}
		tree.children.push(newNode);
		updateXmlFromTree();
		renderTree();
	}

	// Render tree to DOM
	function renderTree() {
		const container = document.getElementById('extension-children');
		container.innerHTML = '';

		if (tree && tree.children) {
			tree.children.forEach(function(child, index) {
				container.appendChild(createNodeElement(child, index, tree.children, tree));
			});
		}
	}

	// Drag and drop state
	let draggedNode = null;
	let draggedNodeData = null;
	let draggedParentArray = null;
	let draggedIndex = null;
	let draggedParentNode = null;

	// Create DOM element for a node
	function createNodeElement(node, index, parentArray, parentNode) {
		const div = document.createElement('div');
		// Check if this is a regex condition (condition with regex attribute)
		const isRegexCond = (node.type === 'condition' && (node.isRegexCondition || node.attributes.regex));
		// Ensure the flag is set for future operations
		if (isRegexCond) node.isRegexCondition = true;
		// Apply 'condition' class for both regular and regex conditions for consistent sizing
		div.className = 'dialplan-node ' + node.type;
		div.dataset.nodeId = 'node-' + (nodeCounter++);
		div.dataset.nodeIndex = index;

		// Store references for drag operations
		div._nodeData = node;
		div._parentArray = parentArray;
		div._parentNode = parentNode;
		div._nodeIndex = index;

		// Drop target events (on the node itself)
		div.addEventListener('dragover', handleDragOver);
		div.addEventListener('dragenter', handleDragEnter);
		div.addEventListener('dragleave', handleDragLeave);
		div.addEventListener('drop', handleDrop);

		// Drag zone (left border area) - this is the only draggable part
		const dragZone = document.createElement('div');
		// Use 'regex-condition' type for conditions with regex attribute
		const displayType = isRegexCond ? 'regex-condition' : node.type;
		const nodeTypeClass = 'type-' + displayType.replace('_', '-');
		dragZone.className = 'dialplan-node-drag-zone ' + nodeTypeClass;
		dragZone.draggable = true;
		dragZone.title = '<?php echo $text['label-drag_to_reorder'] ?? 'Drag to reorder or nest'; ?>';

		// Add rotated label text
		const typeLabel = document.createElement('span');
		typeLabel.className = 'node-type-label';
		// Show "RegEx" for regex conditions, "Anti" for anti-actions, otherwise title case
		if (isRegexCond) {
			typeLabel.textContent = 'RegEx';
		} else if (node.type === 'anti-action') {
			typeLabel.textContent = 'Anti';
		} else {
			typeLabel.textContent = node.type.charAt(0).toUpperCase() + node.type.slice(1);
		}
		dragZone.appendChild(typeLabel);

		dragZone.addEventListener('dragstart', function(e) {
			// Set the parent node as the dragged element
			e.stopPropagation();
			handleDragStart(e, div);
		});
		dragZone.addEventListener('dragend', function(e) {
			e.stopPropagation();
			handleDragEnd(e, div);
		});
		div.appendChild(dragZone);

		// Content row (form + delete button inline)
		const contentRow = document.createElement('div');
		contentRow.className = 'dialplan-node-content';

		// Form fields
		const form = document.createElement('div');
		form.className = 'dialplan-node-form';

		if (node.type === 'condition') {
			form.appendChild(createEnabledToggle(node, div, dragZone, typeLabel));

			if (isRegexCond) {
				// Regex Condition parent - only shows Regex mode and Break
				// Field/Expression belong to the child <regex> elements
				form.appendChild(createFormField('Regex', 'regex', node.attributes.regex || 'all', function(val) {
					node.attributes.regex = val || 'all';
					node.isRegexCondition = true;
					updateXmlFromTree();
				}, ['all', 'any', 'xor']));
				form.appendChild(createFormField('Break', 'break', node.attributes.break || '', function(val) {
					node.attributes.break = val;
					updateXmlFromTree();
				}, ['', 'on-true', 'on-false', 'always', 'never']));
			} else {
				// Regular Condition - shows Field, Expression, Break
				form.appendChild(createFormField('Field', 'field', node.attributes.field || '', function(val) {
					node.attributes.field = val;
					updateXmlFromTree();
				}));
				form.appendChild(createFormField('Expression', 'expression', node.attributes.expression || '', function(val) {
					node.attributes.expression = val;
					updateXmlFromTree();
				}, null, 'field-data'));
				form.appendChild(createFormField('Break', 'break', node.attributes.break || '', function(val) {
					node.attributes.break = val;
					updateXmlFromTree();
				}, ['', 'on-true', 'on-false', 'always', 'never']));
			}

		} else if (node.type === 'regex') {
			form.appendChild(createEnabledToggle(node, div, dragZone, typeLabel));
			form.appendChild(createFormField('Field', 'field', node.attributes.field || '', function(val) {
				node.attributes.field = val;
				updateXmlFromTree();
			}));
			form.appendChild(createFormField('Expression', 'expression', node.attributes.expression || '', function(val) {
				node.attributes.expression = val;
				updateXmlFromTree();
			}, null, 'field-data'));
			form.appendChild(createFormField('Break', 'break', node.attributes.break || '', function(val) {
				node.attributes.break = val;
				updateXmlFromTree();
			}, ['', 'on-true', 'on-false', 'always', 'never']));

		} else if (node.type === 'action' || node.type === 'anti-action') {
			form.appendChild(createEnabledToggle(node, div, dragZone, typeLabel));
			form.appendChild(createFormField('Application', 'application', node.attributes.application || '', function(val) {
				node.attributes.application = val;
				updateXmlFromTree();
			}));
			form.appendChild(createFormField('Data', 'data', node.attributes.data || '', function(val) {
				node.attributes.data = val;
				updateXmlFromTree();
			}, null, 'field-data'));
			form.appendChild(createFormField('Inline', 'inline', node.attributes.inline || '', function(val) {
				node.attributes.inline = val;
				updateXmlFromTree();
			}, ['', 'true', 'false']));
		}

		contentRow.appendChild(form);

		// Delete button (inline with form)
		const actions = document.createElement('div');
		actions.className = 'dialplan-node-actions';

		const deleteBtn = document.createElement('button');
		deleteBtn.type = 'button';
		deleteBtn.className = 'btn btn-sm';
		deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
		deleteBtn.title = '<?php echo $text['button-delete'] ?? 'Delete'; ?>';
		deleteBtn.onclick = function() {
			parentArray.splice(index, 1);
			updateXmlFromTree();
			renderTree();
		};
		actions.appendChild(deleteBtn);
		contentRow.appendChild(actions);

		div.appendChild(contentRow);

		// Children (for conditions only - regex child elements don't have children)
		if (node.type === 'condition') {
			const childrenDiv = document.createElement('div');
			childrenDiv.className = 'dialplan-node-children';
			childrenDiv.dataset.parentNodeId = div.dataset.nodeId;

			// Make children container a drop zone
			childrenDiv._parentNode = node;
			childrenDiv._parentArray = node.children || [];
			childrenDiv.addEventListener('dragover', handleChildrenDragOver);
			childrenDiv.addEventListener('dragenter', handleChildrenDragEnter);
			childrenDiv.addEventListener('dragleave', handleChildrenDragLeave);
			childrenDiv.addEventListener('drop', handleChildrenDrop);

			if (node.children) {
				node.children.forEach(function(child, childIndex) {
					childrenDiv.appendChild(createNodeElement(child, childIndex, node.children, node));
				});
			}

			div.appendChild(childrenDiv);

			// Add buttons - determine which types can be added
			const addBtns = document.createElement('div');
			addBtns.className = 'add-node-buttons';

			// Check if this is a regex condition (using both flag and attribute)
			const isRegexCondition = node.isRegexCondition || node.attributes.regex;

			// Define button configs: [type, displayName, actualType, isRegexCondition]
			const buttonConfigs = [];

			if (isRegexCondition) {
				// Regex condition can have regex children
				buttonConfigs.push(['regex', 'Regex', 'regex', false]);
			}

			// Both can have actions and anti-actions
			buttonConfigs.push(['action', 'Action', 'action', false]);
			buttonConfigs.push(['anti-action', 'Anti-action', 'anti-action', false]);

			// Both can have nested conditions
			buttonConfigs.push(['condition', 'Condition', 'condition', false]);

			// Both can have nested regex conditions
			buttonConfigs.push(['regex-condition', 'Regex Cond', 'condition', true]);

			buttonConfigs.forEach(function(config) {
				const btnType = config[0];
				const displayName = config[1];
				const actualType = config[2];
				const isNewRegexCondition = config[3];

				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'add-node-btn';
				btn.innerHTML = '<i class="fas fa-plus"></i> ' + displayName;
				btn.onclick = function() {
					if (!node.children) node.children = [];
					// Only conditions are containers (regex child elements are not)
					const isContainer = (actualType === 'condition');
					const newNode = { type: actualType, attributes: {}, children: isContainer ? [] : undefined, enabled: true };
					if (isContainer) {
						newNode.attributes = { field: '', expression: '', break: '' };
						if (isNewRegexCondition) {
							newNode.attributes.regex = 'all';
							newNode.isRegexCondition = true;
							// Auto-add a regex child since regex conditions require at least one
							newNode.children.push({
								type: 'regex',
								attributes: { field: '', expression: '', break: '' },
								enabled: true
							});
						}
					} else if (actualType === 'regex') {
						// Regex child element - just field/expression, no children
						newNode.attributes = { field: '', expression: '', break: '' };
					} else {
						newNode.attributes = { application: '', data: '', inline: '' };
					}
					node.children.push(newNode);
					updateXmlFromTree();
					renderTree();
				};
				addBtns.appendChild(btn);
			});

			div.appendChild(addBtns);
		}

		return div;
	}

	// Create enabled toggle switch
	function createEnabledToggle(node, nodeElement, dragZone, typeLabel) {
		const wrapper = document.createElement('div');
		wrapper.className = 'field-enabled';

		const labelEl = document.createElement('label');
		labelEl.textContent = '<?php echo $text['label-enabled'] ?? 'Enabled'; ?>';
		wrapper.appendChild(labelEl);

		// Create toggle switch using checkbox
		const toggleLabel = document.createElement('label');
		toggleLabel.className = 'node-toggle-switch';

		const checkbox = document.createElement('input');
		checkbox.type = 'checkbox';

		// Default to enabled if not explicitly set to false
		if (node.enabled !== false) {
			node.enabled = true;
			checkbox.checked = true;
		} else {
			checkbox.checked = false;
			nodeElement.classList.add('node-disabled');
		}

		checkbox.onchange = function() {
			const isEnabled = this.checked;
			node.enabled = isEnabled;

			if (isEnabled) {
				nodeElement.classList.remove('node-disabled');
				// Re-enable all children when parent is re-enabled (conditions only)
				if (node.type === 'condition' && node.children) {
					enableAllChildren(node.children);
				}
			} else {
				nodeElement.classList.add('node-disabled');
				// Disable all children when parent is disabled (conditions only)
				if (node.type === 'condition' && node.children) {
					disableAllChildren(node.children);
					// For regex conditions, also disable all child regex elements specifically
					if (node.isRegexCondition) {
						disableChildRegexElements(node.children);
					}
				}
			}

			updateXmlFromTree();
			renderTree(); // Re-render to update child toggle states
		};

		const slider = document.createElement('span');
		slider.className = 'node-toggle-slider';

		toggleLabel.appendChild(checkbox);
		toggleLabel.appendChild(slider);
		wrapper.appendChild(toggleLabel);
		return wrapper;
	}

	// Recursively disable all children (simulates clicking off their toggles)
	function disableAllChildren(children) {
		if (!children) return;
		children.forEach(function(child) {
			child.enabled = false;
			if (child.children) {
				disableAllChildren(child.children);
			}
		});
	}

	// Specifically disable child regex elements within a regex condition
	// Regex children can only exist in a regex condition parent
	function disableChildRegexElements(children) {
		if (!children) return;
		children.forEach(function(child) {
			if (child.type === 'regex') {
				child.enabled = false;
			}
			if (child.children) {
				disableChildRegexElements(child.children);
			}
		});
	}

	// Recursively enable all children (simulates clicking on their toggles)
	function enableAllChildren(children) {
		if (!children) return;
		children.forEach(function(child) {
			child.enabled = true;
			if (child.children) {
				enableAllChildren(child.children);
			}
		});
	}

	// Create form field element
	function createFormField(label, name, value, onChange, options, cssClass) {
		const wrapper = document.createElement('div');
		if (cssClass) wrapper.className = cssClass;

		const labelEl = document.createElement('label');
		labelEl.textContent = label;
		wrapper.appendChild(labelEl);

		let input;
		if (options) {
			input = document.createElement('select');
			input.className = 'formfld';
			options.forEach(function(opt) {
				const option = document.createElement('option');
				option.value = opt;
				option.textContent = opt || '';
				if (opt === value) option.selected = true;
				input.appendChild(option);
			});
			input.onchange = function() {
				onChange(this.value);
			};
			wrapper.appendChild(input);
		} else if (name === 'application' && availableApplications.length > 0) {
			// Application field with autocomplete
			const autocompleteWrapper = document.createElement('div');
			autocompleteWrapper.className = 'autocomplete-wrapper';

			input = document.createElement('input');
			input.type = 'text';
			input.className = 'formfld';
			input.value = value;
			input.setAttribute('autocomplete', 'off');

			const dropdown = document.createElement('div');
			dropdown.className = 'autocomplete-dropdown';

			// Validate initial value
			validateApplicationInput(input, value);

			// Filter and show dropdown
			function showDropdown(filterText) {
				dropdown.innerHTML = '';
				const filter = filterText.toLowerCase();
				const matches = availableApplications.filter(function(app) {
					return app.toLowerCase().includes(filter);
				}).slice(0, 20); // Limit to 20 results

				if (matches.length === 0) {
					dropdown.classList.remove('visible');
					return;
				}

				matches.forEach(function(app, index) {
					const item = document.createElement('div');
					item.className = 'autocomplete-item';
					if (index === 0) item.classList.add('selected');

					const appName = document.createElement('div');
					appName.className = 'app-name';
					appName.textContent = app;
					item.appendChild(appName);

					item.onclick = function() {
						input.value = app;
						validateApplicationInput(input, app);
						onChange(app);
						dropdown.classList.remove('visible');
					};

					dropdown.appendChild(item);
				});

				dropdown.classList.add('visible');
			}

			// Validate application input
			function validateApplicationInput(inputEl, appValue) {
				if (!appValue) {
					inputEl.classList.remove('valid-application', 'invalid-application');
					return;
				}

				const isValid = availableApplications.some(function(app) {
					return app.toLowerCase() === appValue.toLowerCase();
				});

				if (isValid) {
					inputEl.classList.add('valid-application');
					inputEl.classList.remove('invalid-application');
				} else {
					inputEl.classList.add('invalid-application');
					inputEl.classList.remove('valid-application');
				}
			}

			input.onfocus = function() {
				showDropdown(this.value);
			};

			input.oninput = function() {
				showDropdown(this.value);
				validateApplicationInput(this, this.value);
				onChange(this.value);
			};

			input.onblur = function() {
				// Delay to allow click on dropdown item
				setTimeout(function() {
					dropdown.classList.remove('visible');
				}, 200);
			};

			input.onkeydown = function(e) {
				const items = dropdown.querySelectorAll('.autocomplete-item');
				const selected = dropdown.querySelector('.autocomplete-item.selected');
				let selectedIndex = Array.from(items).indexOf(selected);

				if (e.key === 'ArrowDown') {
					e.preventDefault();
					if (selectedIndex < items.length - 1) {
						if (selected) selected.classList.remove('selected');
						items[selectedIndex + 1].classList.add('selected');
						items[selectedIndex + 1].scrollIntoView({ block: 'nearest' });
					}
				} else if (e.key === 'ArrowUp') {
					e.preventDefault();
					if (selectedIndex > 0) {
						if (selected) selected.classList.remove('selected');
						items[selectedIndex - 1].classList.add('selected');
						items[selectedIndex - 1].scrollIntoView({ block: 'nearest' });
					}
				} else if (e.key === 'Enter') {
					e.preventDefault();
					if (selected) {
						const appName = selected.querySelector('.app-name').textContent;
						input.value = appName;
						validateApplicationInput(input, appName);
						onChange(appName);
						dropdown.classList.remove('visible');
					}
				} else if (e.key === 'Escape') {
					dropdown.classList.remove('visible');
				}
			};

			autocompleteWrapper.appendChild(input);
			autocompleteWrapper.appendChild(dropdown);
			wrapper.appendChild(autocompleteWrapper);
		} else if (name === 'field') {
			// Field autocomplete for condition/regex
			const autocompleteWrapper = document.createElement('div');
			autocompleteWrapper.className = 'autocomplete-wrapper';

			input = document.createElement('input');
			input.type = 'text';
			input.className = 'formfld';
			input.value = value;
			input.setAttribute('autocomplete', 'off');

			const dropdown = document.createElement('div');
			dropdown.className = 'autocomplete-dropdown';

			// Filter and show dropdown
			function showFieldDropdown(filterText) {
				dropdown.innerHTML = '';
				const filter = filterText.toLowerCase();
				const matches = availableFields.filter(function(field) {
					return field.value.toLowerCase().includes(filter) ||
					       field.label.toLowerCase().includes(filter);
				}).slice(0, 20); // Limit to 20 results

				if (matches.length === 0) {
					dropdown.classList.remove('visible');
					return;
				}

				matches.forEach(function(field, index) {
					const item = document.createElement('div');
					item.className = 'autocomplete-item';
					if (index === 0) item.classList.add('selected');

					const fieldName = document.createElement('div');
					fieldName.className = 'app-name';
					fieldName.textContent = field.value;
					item.appendChild(fieldName);

					// Show label if different from value
					if (field.label !== field.value) {
						const fieldLabel = document.createElement('div');
						fieldLabel.className = 'app-description';
						fieldLabel.textContent = field.label;
						item.appendChild(fieldLabel);
					}

					item.onclick = function() {
						input.value = field.value;
						onChange(field.value);
						dropdown.classList.remove('visible');
					};

					dropdown.appendChild(item);
				});

				dropdown.classList.add('visible');
			}

			input.onfocus = function() {
				showFieldDropdown(this.value);
			};

			input.oninput = function() {
				showFieldDropdown(this.value);
				onChange(this.value);
			};

			input.onblur = function() {
				// Delay to allow click on dropdown item
				setTimeout(function() {
					dropdown.classList.remove('visible');
				}, 200);
			};

			input.onkeydown = function(e) {
				const items = dropdown.querySelectorAll('.autocomplete-item');
				const selected = dropdown.querySelector('.autocomplete-item.selected');
				let selectedIndex = Array.from(items).indexOf(selected);

				if (e.key === 'ArrowDown') {
					e.preventDefault();
					if (selectedIndex < items.length - 1) {
						if (selected) selected.classList.remove('selected');
						items[selectedIndex + 1].classList.add('selected');
						items[selectedIndex + 1].scrollIntoView({ block: 'nearest' });
					}
				} else if (e.key === 'ArrowUp') {
					e.preventDefault();
					if (selectedIndex > 0) {
						if (selected) selected.classList.remove('selected');
						items[selectedIndex - 1].classList.add('selected');
						items[selectedIndex - 1].scrollIntoView({ block: 'nearest' });
					}
				} else if (e.key === 'Enter') {
					e.preventDefault();
					if (selected) {
						const fieldValue = selected.querySelector('.app-name').textContent;
						input.value = fieldValue;
						onChange(fieldValue);
						dropdown.classList.remove('visible');
					}
				} else if (e.key === 'Escape') {
					dropdown.classList.remove('visible');
				}
			};

			autocompleteWrapper.appendChild(input);
			autocompleteWrapper.appendChild(dropdown);
			wrapper.appendChild(autocompleteWrapper);
		} else {
			input = document.createElement('input');
			input.type = 'text';
			input.className = 'formfld';
			input.value = value;

			input.onchange = function() {
				onChange(this.value);
			};
			input.oninput = function() {
				onChange(this.value);
			};

			wrapper.appendChild(input);
		}

		return wrapper;
	}

	// Drag and Drop Event Handlers
	function handleDragStart(e, nodeEl) {
		if (!nodeEl) {
			nodeEl = e.target.closest('.dialplan-node');
		}
		if (!nodeEl) return;

		draggedNode = nodeEl;
		draggedNodeData = nodeEl._nodeData;
		draggedParentArray = nodeEl._parentArray;
		draggedParentNode = nodeEl._parentNode;
		draggedIndex = nodeEl._nodeIndex;

		nodeEl.classList.add('dragging');
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData('text/plain', nodeEl.dataset.nodeId);
	}

	function handleDragEnd(e, nodeEl) {
		if (!nodeEl) {
			nodeEl = e.target.closest('.dialplan-node');
		}
		if (nodeEl) {
			nodeEl.classList.remove('dragging');
		}

		// Clear all drag-over classes
		document.querySelectorAll('.drag-over, .drag-over-above, .drag-over-below').forEach(function(el) {
			el.classList.remove('drag-over', 'drag-over-above', 'drag-over-below');
		});

		draggedNode = null;
		draggedNodeData = null;
		draggedParentArray = null;
		draggedParentNode = null;
		draggedIndex = null;
	}

	function handleDragOver(e) {
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';

		const nodeEl = e.target.closest('.dialplan-node');
		if (!nodeEl || nodeEl === draggedNode) return;

		// Determine drop position (above, below, or inside for conditions only)
		const rect = nodeEl.getBoundingClientRect();
		const y = e.clientY - rect.top;
		const height = rect.height;

		// Only conditions (including regex conditions) can accept children via drop
		// Regex child elements (type='regex') are NOT containers
		const isDropContainer = nodeEl._nodeData && nodeEl._nodeData.type === 'condition';

		nodeEl.classList.remove('drag-over', 'drag-over-above', 'drag-over-below');

		if (y < height * 0.25) {
			nodeEl.classList.add('drag-over-above');
		} else if (y > height * 0.75 || !isDropContainer) {
			nodeEl.classList.add('drag-over-below');
		} else {
			// Drop inside condition
			nodeEl.classList.add('drag-over');
		}
	}

	function handleDragEnter(e) {
		e.preventDefault();
	}

	function handleDragLeave(e) {
		const nodeEl = e.target.closest('.dialplan-node');
		if (nodeEl && !nodeEl.contains(e.relatedTarget)) {
			nodeEl.classList.remove('drag-over', 'drag-over-above', 'drag-over-below');
		}
	}

	function handleDrop(e) {
		e.preventDefault();
		e.stopPropagation();

		const targetNodeEl = e.target.closest('.dialplan-node');
		if (!targetNodeEl || !draggedNodeData || targetNodeEl === draggedNode) {
			handleDragEnd(e);
			return;
		}

		const targetNodeData = targetNodeEl._nodeData;
		const targetParentArray = targetNodeEl._parentArray;
		const targetIndex = targetNodeEl._nodeIndex;

		// Determine drop position
		const rect = targetNodeEl.getBoundingClientRect();
		const y = e.clientY - rect.top;
		const height = rect.height;

		// Only conditions (including regex conditions) can accept children
		// Regex child elements (type='regex') are NOT containers
		const isDropContainer = targetNodeData.type === 'condition';

		// Remove from original position
		const removedNode = draggedParentArray.splice(draggedIndex, 1)[0];

		if (y < height * 0.25) {
			// Insert above target
			targetParentArray.splice(targetIndex, 0, removedNode);
		} else if (y > height * 0.75 || !isDropContainer) {
			// Insert below target
			const newIndex = targetParentArray === draggedParentArray && draggedIndex < targetIndex ? targetIndex : targetIndex + 1;
			targetParentArray.splice(newIndex, 0, removedNode);
		} else {
			// Insert inside condition
			if (!targetNodeData.children) targetNodeData.children = [];
			targetNodeData.children.push(removedNode);
		}

		updateXmlFromTree();
		renderTree();
		handleDragEnd(e);
	}

	// Children container drag handlers (for dropping into empty conditions)
	function handleChildrenDragOver(e) {
		e.preventDefault();
		e.stopPropagation();
		e.dataTransfer.dropEffect = 'move';

		// Add visual feedback to the container
		const childrenDiv = e.currentTarget;
		if (childrenDiv && childrenDiv.classList.contains('dialplan-node-children')) {
			childrenDiv.classList.add('drag-over');
		}
	}

	function handleChildrenDragEnter(e) {
		e.preventDefault();
		e.stopPropagation();
		const childrenDiv = e.currentTarget;
		if (childrenDiv && childrenDiv.classList.contains('dialplan-node-children')) {
			childrenDiv.classList.add('drag-over');
		}
	}

	function handleChildrenDragLeave(e) {
		e.stopPropagation();
		const childrenDiv = e.currentTarget;
		if (childrenDiv && childrenDiv.classList.contains('dialplan-node-children')) {
			// Only remove if we're actually leaving the container (not entering a child)
			const rect = childrenDiv.getBoundingClientRect();
			if (e.clientX < rect.left || e.clientX > rect.right ||
				e.clientY < rect.top || e.clientY > rect.bottom) {
				childrenDiv.classList.remove('drag-over');
			}
		}
	}

	function handleChildrenDrop(e) {
		e.preventDefault();
		e.stopPropagation();

		const childrenDiv = e.currentTarget.classList.contains('dialplan-node-children')
			? e.currentTarget
			: e.target.closest('.dialplan-node-children');

		if (!childrenDiv || !draggedNodeData) {
			handleDragEnd(e);
			return;
		}

		childrenDiv.classList.remove('drag-over');

		const targetParentNode = childrenDiv._parentNode;
		if (!targetParentNode) {
			handleDragEnd(e);
			return;
		}

		// Remove from original position
		const removedNode = draggedParentArray.splice(draggedIndex, 1)[0];

		// Add to new parent
		if (!targetParentNode.children) targetParentNode.children = [];
		targetParentNode.children.push(removedNode);

		updateXmlFromTree();
		renderTree();
		handleDragEnd(e);
	}

	// Update XML from tree (UI -> XML sync)
	function updateXmlFromTree() {
		if (!tree) return;
		isDirty = true;

		// Update extension attributes from form
		tree.attributes.name = document.getElementById('dialplan_name').value || '';
		tree.attributes.continue = document.getElementById('dialplan_continue').value || 'false';

		const xml = DialplanParser.generateXmlFromTree(tree);

		skipAceChange = true;
		editor.setValue(xml, -1);
		skipAceChange = false;

		// Stay in synced state since UI changed the XML
		setSyncState('synced');
	}

	// Add node to extension root
	window.addNode = function(type, containerId) {
		if (!tree) {
			tree = {
				type: 'extension',
				attributes: { name: '', continue: 'false', uuid: '' },
				children: []
			};
		}

		// Handle regex-condition as a special type that creates a condition with regex="all"
		const actualType = (type === 'regex-condition') ? 'condition' : type;
		// Only conditions are containers (regex child elements are not)
		const isContainer = (actualType === 'condition');
		const newNode = { type: actualType, attributes: {}, children: isContainer ? [] : undefined, enabled: true };

		if (isContainer) {
			newNode.attributes = { field: '', expression: '', break: '' };
			// If adding a regex condition, set the regex attribute and flag, and add initial regex child
			if (type === 'regex-condition') {
				newNode.attributes.regex = 'all';
				newNode.isRegexCondition = true;
				// Auto-add a regex child since regex conditions require at least one
				newNode.children.push({
					type: 'regex',
					attributes: { field: '', expression: '', break: '' },
					enabled: true
				});
			}
		} else {
			newNode.attributes = { application: '', data: '', inline: '' };
		}
		tree.children.push(newNode);
		updateXmlFromTree();
		renderTree();
	};

	// Toggle XML panel visibility
	window.toggleXmlPanel = function() {
		const panel = document.getElementById('xml-panel');
		const icon = document.getElementById('toggle-icon');
		const toggleBtn = document.getElementById('panel-toggle');
		xmlPanelVisible = !xmlPanelVisible;

		if (xmlPanelVisible) {
			panel.classList.remove('collapsed');
			panel.style.width = xmlPanelWidth + 'px';
			icon.className = 'fas fa-chevron-right';
			toggleBtn.classList.remove('visible');
		} else {
			panel.classList.add('collapsed');
			icon.className = 'fas fa-chevron-left';
			toggleBtn.classList.add('visible');
		}

		// Save preference
		saveXmlVisibility(xmlPanelVisible);

		// Resize ACE editor
		setTimeout(function() {
			if (editor) editor.resize();
		}, 350);
	};

	// XML Panel Resize Handle
	let xmlPanelWidth = 500; // Default width in pixels
	let isResizing = false;

	function initXmlResize() {
		const handle = document.getElementById('xml-resize-handle');
		const panel = document.getElementById('xml-panel');
		const container = document.querySelector('.dialplan-editor-container');

		if (!handle || !panel || !container) return;

		// Initialize width from current panel width if visible
		if (xmlPanelVisible && panel.offsetWidth > 0) {
			xmlPanelWidth = panel.offsetWidth;
		}

		handle.addEventListener('mousedown', function(e) {
			e.preventDefault();
			isResizing = true;
			handle.classList.add('dragging');
			document.body.style.cursor = 'col-resize';
			document.body.style.userSelect = 'none';
		});

		document.addEventListener('mousemove', function(e) {
			if (!isResizing) return;

			const containerRect = container.getBoundingClientRect();
			const newWidth = containerRect.right - e.clientX;
			const toggleBtn = document.getElementById('panel-toggle');

			// Minimum width before collapse
			const minWidth = 150;
			const collapseThreshold = 100;
			const maxWidth = containerRect.width * 0.8;

			if (newWidth < collapseThreshold) {
				// Auto-collapse when dragged too narrow
				if (xmlPanelVisible) {
					xmlPanelVisible = false;
					panel.classList.add('collapsed');
					document.getElementById('toggle-icon').className = 'fas fa-chevron-left';
					toggleBtn.classList.add('visible');
					saveXmlVisibility(false);
				}
			} else {
				// Expand if collapsed and dragging wider
				if (!xmlPanelVisible && newWidth >= minWidth) {
					xmlPanelVisible = true;
					panel.classList.remove('collapsed');
					document.getElementById('toggle-icon').className = 'fas fa-chevron-right';
					toggleBtn.classList.remove('visible');
					saveXmlVisibility(true);
				}

				// Clamp width
				xmlPanelWidth = Math.max(minWidth, Math.min(maxWidth, newWidth));
				panel.style.width = xmlPanelWidth + 'px';
			}

			// Resize ACE editor during drag
			if (editor) editor.resize();
		});

		document.addEventListener('mouseup', function() {
			if (isResizing) {
				isResizing = false;
				handle.classList.remove('dragging');
				document.body.style.cursor = '';
				document.body.style.userSelect = '';

				// Final resize of ACE editor
				if (editor) editor.resize();
			}
		});
	}

	// Initialize resize handle after DOM is ready
	document.addEventListener('DOMContentLoaded', initXmlResize);

	// Save XML panel visibility preference
	function saveXmlVisibility(visible) {
		const formData = new FormData();
		formData.append('ajax_action', 'save_xml_visibility');
		formData.append('visible', visible ? 'true' : 'false');
		formData.append('<?php echo $token['name']; ?>', '<?php echo $token['hash']; ?>');

		fetch(window.location.href, {
			method: 'POST',
			body: formData
		}).catch(function() {});
	}

	// Mobile panel switching
	window.switchMobilePanel = function(panel) {
		const visualPanel = document.getElementById('visual-panel');
		const xmlPanel = document.getElementById('xml-panel');
		const tabVisual = document.getElementById('tab-visual');
		const tabXml = document.getElementById('tab-xml');

		if (panel === 'visual') {
			visualPanel.classList.remove('mobile-hidden');
			xmlPanel.classList.add('mobile-hidden');
			tabVisual.classList.add('active');
			tabXml.classList.remove('active');
		} else {
			visualPanel.classList.add('mobile-hidden');
			xmlPanel.classList.remove('mobile-hidden');
			tabVisual.classList.remove('active');
			tabXml.classList.add('active');
			setTimeout(function() { editor.resize(); }, 100);
		}
	};

	// Change ACE theme
	window.changeTheme = function() {
		const theme = document.getElementById('theme').value;
		editor.setTheme('ace/theme/' + theme);
	};

	// Validate regex conditions have at least one regex child
	function validateRegexConditions(nodes) {
		if (!nodes) return { valid: true };
		for (const node of nodes) {
			if (node.type === 'condition' && node.isRegexCondition && node.enabled !== false) {
				// Check if it has at least one enabled regex child
				const hasRegexChild = node.children && node.children.some(function(child) {
					return child.type === 'regex' && child.enabled !== false;
				});
				if (!hasRegexChild) {
					return {
						valid: false,
						message: '<?php echo $text['error-regex_condition_needs_child'] ?? 'A Regex Condition must have at least one Regex child element.'; ?>'
					};
				}
			}
			// Recursively check children
			if (node.children) {
				const childResult = validateRegexConditions(node.children);
				if (!childResult.valid) return childResult;
			}
		}
		return { valid: true };
	}

	// Form submission handling
	document.getElementById('frm').addEventListener('submit', function(e) {
		// Always use XML from ACE editor
		document.getElementById('dialplan_xml_hidden').value = editor.getValue();

		// Validate regex conditions
		if (tree && tree.children) {
			const validation = validateRegexConditions(tree.children);
			if (!validation.valid) {
				e.preventDefault();
				alert(validation.message);
				return false;
			}
		}

		// Check if confirmation needed
		if (syncState === 'stale') {
			e.preventDefault();
			modal_open('modal-save-stale', 'btn_save');
			return false;
		}

		if (syncState === 'error') {
			e.preventDefault();
			modal_open('modal-save-error', 'btn_save');
			return false;
		}

		isDirty = false;
		return true;
	});

	// Confirm save after modal
	window.confirmSave = function() {
		modal_close();
		document.getElementById('dialplan_xml_hidden').value = editor.getValue();
		isDirty = false;
		document.getElementById('frm').submit();
	};

	<?php if ($is_migration): ?>
	// Migration confirmation
	window.confirmMigration = function() {
		modal_close();
		document.getElementById('frm').submit();
	};
	<?php endif; ?>

	// Ctrl+S handling
	document.addEventListener('keydown', function(e) {
		if ((e.ctrlKey || e.metaKey) && e.key === 's') {
			e.preventDefault();
			document.getElementById('btn_save').click();
		}
	});

	// Unsaved changes warning
	window.addEventListener('beforeunload', function(e) {
		if (isDirty) {
			e.preventDefault();
			e.returnValue = '';
			return '';
		}
	});

	// Handle viewport changes
	const mediaQuery = window.matchMedia('(max-width: 1024px)');
	mediaQuery.addEventListener('change', function(e) {
		isMobile = e.matches;
		if (isMobile) {
			// Default to visual panel on mobile
			switchMobilePanel('visual');
		}
		setTimeout(function() { editor.resize(); }, 100);
	});

	// Sync dialplan name with extension name
	document.getElementById('dialplan_name').addEventListener('input', function() {
		if (tree) {
			tree.attributes.name = this.value;
			updateXmlFromTree();
		}
	});

	document.getElementById('dialplan_continue').addEventListener('change', function() {
		if (tree) {
			tree.attributes.continue = this.value;
			updateXmlFromTree();
		}
	});

	// Initialize on DOM ready
	document.addEventListener('DOMContentLoaded', initEditor);
})();
</script>

<?php require_once "resources/footer.php"; ?>
