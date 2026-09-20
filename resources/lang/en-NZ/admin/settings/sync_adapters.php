<?php

/*
| ChainMate overrides. Only the keys listed here differ from en-US; every
| other key falls back to resources/lang/en-US one key at a time.
*/

return [
    'help' => 'Sync host inventory from external systems into ChainMate.',
    'large_fleet_note' => 'For large fleets, schedule these CLI commands instead of clicking the buttons above: :pull_command to pull inventory, :push_command to push ChainMate values back to the vendor.',
    'empty_state_intro' => 'Sync adapters connect ChainMate to the MDM, RMM, and endpoint tools you already use, so devices and their assigned users flow into ChainMate automatically instead of being typed in by hand.',
    'empty_state_supported_intro' => 'ChainMate currently supports :count adapter types out of the box:',
    'company_scope_help' => 'When a company is selected here, every asset synced from this adapter is automatically assigned to that company on the ChainMate side, inheriting your company-level scoping and permissions. Leave blank to sync assets into the shared no-company pool. This is separate from the "Filter by company" dropdown at the top of the page, which is a view filter only and does not change any adapter\'s sync behavior.',
    'empty_state_company_intro' => 'Each adapter can be scoped to a single ChainMate company. When set, every asset the adapter creates or updates is automatically assigned to that company, so different tenants stay separated even when the sync runs against the same vendor account. Leaving the company blank routes an adapter\'s synced assets into the shared, no-company pool.',
    'asset_tag_pattern_help' => 'Applied only to newly-created assets from this adapter. Supported placeholders: <code>{serial}</code>, <code>{external_id}</code>, <code>{hostname}</code>, <code>{model}</code>, <code>{source}</code>. Leave blank to fall back to ChainMate\'s auto-increment setting. If auto-increment is also off, the asset tag defaults to <code>{source}-{external_id}</code>. Assets already synced keep their existing asset tag.',
    'defaults_section_intro' => 'How ChainMate should handle new assets and user assignments coming from this adapter.',
    'default_category_help' => 'When a device from this adapter reports a hardware model that doesn\'t exist in ChainMate yet, a new asset model is created and placed in this category. Leave blank to use the "Discovered Hardware" category.',
    'default_status_help' => 'When a device from this adapter is created in ChainMate for the first time, its status label is set to this value. Leave blank to use the first deployable status label (or the first status label if none are marked deployable).',
    'user_match_strategy' => 'Assign to ChainMate user',
    'push_notes_section_intro' => 'Optional composite field where you can assemble multiple attributes from an asset in ChainMate and push it into a compatible field on the vendor side. This is useful for vendors that do not support multiple fields or custom attributes, but do support a single notes field.',
    'group_mapping_title' => ':label to ChainMate company mapping',
    'group_mapping_intro' => 'Map each :label from the vendor to a ChainMate company. Synced devices are added in the mapped company. Unmapped groups fall back to this adapter\'s own company setting. Click Refresh to pull the current list from the vendor.',
    'custom_source_id_path_help' => 'Required. Dot-path within one record pointing at the vendor\'s stable unique id for that record, e.g. <code>id</code>, <code>uuid</code>, or <code>serial_number</code>. ChainMate uses this value to match records across sync runs so subsequent pulls update the same asset rather than creating duplicates.',
    'custom_pagination_style_help' => 'How the adapter walks past the vendor\'s first page of results. <code>None</code> sends a single request and stops. <code>Offset + Limit</code> re-hits the same endpoint with <code>?limit=X&offset=Y</code> params. <code>Page Number + Limit</code> re-hits with <code>?limit=X&page=N</code> (ChainMate API, Laravel-style APIs). <code>Next URL</code> follows an absolute URL returned in each response.',
    'custom_field_paths_help' => 'This section tells the adapter WHERE to find each field in your vendor\'s JSON response. Pick a ChainMate field, type the dot-path where your API returns that value in one record (e.g. <code>hardware.serial</code>), then click Add. Which ChainMate column each value is synces to, and whether it flows pull / push / both, is configured in the field-mapping section further down.',
    'field_map_column_field' => 'ChainMate Field',
    'custom_section_extras_help' => 'Additional vendor fields not covered by the standard ChainMate mapping. Configuration for each extra field is done in the mapping section further down.',
    'extra_fields_section_intro' => 'Additional vendor fields that don\'t have a corresponding ChainMate field. These can be mapped to custom fields or native ChainMate columns in the field-mapping section below. Boolean values can be mapped to checkbox-type custom fields, text fields can be mapped to text-type custom fields.',
];
