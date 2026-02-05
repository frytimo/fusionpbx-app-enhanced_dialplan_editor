#!/usr/bin/php
<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2026
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>

	Dialplan Rollback Tool
	----------------------
	CLI-only tool to rollback a dialplan from unified editor to legacy mode.
	This regenerates the dialplan_xml from the preserved dialplan_details rows.

	Usage:
		php dialplan_rollback.php --dialplan-uuid=<uuid>
		php dialplan_rollback.php --list
		php dialplan_rollback.php --help

	Options:
		--dialplan-uuid=<uuid>  UUID of the dialplan to rollback
		--list                  List all dialplans currently using unified editor
		--force                 Skip confirmation prompt
		--help                  Show this help message
*/

// Ensure CLI-only execution
if (php_sapi_name() !== 'cli') {
	die("Error: This script can only be run from the command line.\n");
}

// Parse command line arguments
$options = getopt('', ['dialplan-uuid:', 'list', 'force', 'help']);

// Show help
if (isset($options['help']) || (empty($options['dialplan-uuid']) && !isset($options['list']))) {
	echo <<<HELP
FusionPBX Dialplan Rollback Tool
=================================

This CLI tool rolls back a dialplan from the unified XML-only editor to the
legacy detail-based editor. It regenerates dialplan_xml from the preserved
v_dialplan_details rows.

Usage:
  php dialplan_rollback.php --dialplan-uuid=<uuid>
  php dialplan_rollback.php --list
  php dialplan_rollback.php --help

Options:
  --dialplan-uuid=<uuid>  UUID of the dialplan to rollback
  --list                  List all dialplans currently using unified editor
  --force                 Skip confirmation prompt
  --help                  Show this help message

Examples:
  php dialplan_rollback.php --list
  php dialplan_rollback.php --dialplan-uuid=abc12345-1234-5678-abcd-1234567890ab
  php dialplan_rollback.php --dialplan-uuid=abc12345-1234-5678-abcd-1234567890ab --force


HELP;
	exit(0);
}

// Include FusionPBX resources
$document_root = dirname(__DIR__, 3);
require_once $document_root . "/resources/require.php";
require_once $document_root . "/resources/functions.php";

// Initialize database
$database = database::new();

// List unified dialplans
if (isset($options['list'])) {
	echo "\nDialplans using unified editor:\n";
	echo str_repeat('-', 80) . "\n";

	$sql = "SELECT dialplan_uuid, dialplan_name, dialplan_context, dialplan_number, domain_uuid ";
	$sql .= "FROM v_dialplans ";
	$sql .= "WHERE dialplan_editor_version = 'unified' ";
	$sql .= "ORDER BY dialplan_context, dialplan_name";

	$dialplans = $database->select($sql, [], 'all');

	if (empty($dialplans)) {
		echo "No dialplans are currently using the unified editor.\n\n";
		exit(0);
	}

	printf("%-36s  %-30s  %-15s  %s\n", "UUID", "Name", "Context", "Number");
	echo str_repeat('-', 80) . "\n";

	foreach ($dialplans as $row) {
		printf("%-36s  %-30s  %-15s  %s\n",
			$row['dialplan_uuid'],
			substr($row['dialplan_name'], 0, 30),
			substr($row['dialplan_context'], 0, 15),
			$row['dialplan_number'] ?? ''
		);
	}

	echo "\nTotal: " . count($dialplans) . " dialplan(s)\n\n";
	exit(0);
}

// Validate dialplan UUID
$dialplan_uuid = $options['dialplan-uuid'] ?? '';

if (!is_uuid($dialplan_uuid)) {
	echo "Error: Invalid dialplan UUID format.\n";
	exit(1);
}

// Fetch the dialplan
$sql = "SELECT * FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid";
$parameters['dialplan_uuid'] = $dialplan_uuid;
$dialplan = $database->select($sql, $parameters, 'row');
unset($sql, $parameters);

if (empty($dialplan)) {
	echo "Error: Dialplan not found with UUID: {$dialplan_uuid}\n";
	exit(1);
}

// Check current editor version
if ($dialplan['dialplan_editor_version'] !== 'unified') {
	echo "Info: Dialplan '{$dialplan['dialplan_name']}' is not using the unified editor.\n";
	echo "Current editor version: " . ($dialplan['dialplan_editor_version'] ?: 'legacy (default)') . "\n";
	exit(0);
}

// Check if dialplan_details exist
$sql = "SELECT COUNT(*) as count FROM v_dialplan_details WHERE dialplan_uuid = :dialplan_uuid";
$parameters['dialplan_uuid'] = $dialplan_uuid;
$detail_count = $database->select($sql, $parameters, 'column');
unset($sql, $parameters);

if ((int)$detail_count === 0) {
	echo "Error: No dialplan_details found for this dialplan.\n";
	echo "Cannot rollback - legacy details were not preserved or never existed.\n";
	exit(1);
}

// Display dialplan info
echo "\nDialplan Rollback\n";
echo str_repeat('=', 50) . "\n";
echo "UUID:        {$dialplan_uuid}\n";
echo "Name:        {$dialplan['dialplan_name']}\n";
echo "Context:     {$dialplan['dialplan_context']}\n";
echo "Number:      " . ($dialplan['dialplan_number'] ?: '(none)') . "\n";
echo "Details:     {$detail_count} row(s) available for regeneration\n";
echo str_repeat('-', 50) . "\n";

// Confirmation prompt
if (!isset($options['force'])) {
	echo "\nThis will:\n";
	echo "  1. Regenerate dialplan_xml from the legacy dialplan_details\n";
	echo "  2. Set dialplan_editor_version to 'legacy'\n";
	echo "  3. Clear the dialplan cache\n";
	echo "\nThe current XML will be overwritten. Continue? [y/N]: ";

	$handle = fopen("php://stdin", "r");
	$line = fgets($handle);
	fclose($handle);

	if (strtolower(trim($line)) !== 'y') {
		echo "Rollback cancelled.\n";
		exit(0);
	}
}

echo "\nProcessing rollback...\n";

// Fetch dialplan details
$sql = "SELECT * FROM v_dialplan_details ";
$sql .= "WHERE dialplan_uuid = :dialplan_uuid ";
$sql .= "ORDER BY dialplan_detail_group ASC, dialplan_detail_order ASC";
$parameters['dialplan_uuid'] = $dialplan_uuid;
$details = $database->select($sql, $parameters, 'all');
unset($sql, $parameters);

// Build the dialplan array for XML generation
$dialplan_array = [];
$dialplan_array['dialplans'][0] = $dialplan;
$dialplan_array['dialplans'][0]['dialplan_details'] = $details;

// Use the dialplan class to regenerate XML
require_once dirname(__DIR__) . "/resources/classes/dialplan.php";

$dialplan_obj = new dialplan;
$dialplan_obj->source = "details";
$dialplan_obj->destination = "array";
$dialplan_obj->uuid = $dialplan_uuid;
$dialplan_obj->prepare_details($dialplan_array);
$xml_array = $dialplan_obj->xml();

$new_xml = $xml_array[$dialplan_uuid] ?? '';

if (empty($new_xml)) {
	echo "Error: Failed to generate XML from dialplan details.\n";
	exit(1);
}

// Update the database
$update_array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
$update_array['dialplans'][0]['dialplan_xml'] = $new_xml;
$update_array['dialplans'][0]['dialplan_editor_version'] = 'legacy';

$database->save($update_array);

echo "  [OK] Database updated\n";

// Clear the cache
$cache = new cache;
$dialplan_context = $dialplan['dialplan_context'];
if ($dialplan_context == "\${domain_name}" || $dialplan_context == "global") {
	$cache->delete("dialplan:*");
} else {
	$cache->delete("dialplan:" . $dialplan_context);
}

echo "  [OK] Cache cleared\n";

echo "\nRollback completed successfully!\n";
echo "Dialplan '{$dialplan['dialplan_name']}' is now using the legacy editor.\n\n";

exit(0);
