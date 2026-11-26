<?php
/**
 * AssetMerge — relation map and field conflict policy.
 *
 * P1+P2 read-only preview: this class only DESCRIBES what a future merge
 * would touch. It performs no writes.
 *
 * Relation list is derived from GLPI 12 Computer::getCloneRelations() and
 * CommonDBTM::$forward_entity_to (see glpi core src/Computer.php, tag 12.0.0).
 */

namespace GlpiPlugin\AssetMerge;

final class RelationMap
{
    /**
     * Relations that a Computer merge would need to move from source to target.
     *
     * Each entry:
     *   class   => GLPI class name
     *   table   => database table (used for counting)
     *   where   => column(s) referencing the item id
     *   note    => human readable handling note
     *
     * glpi_logs is deliberately excluded: history must stay with the source
     * record for audit traceability (soft-deleted, still readable).
     *
     * @return array<int,array{class:string,table:string,where:string,note:string}>
     */
    public static function relations(): array
    {
        return [
            ['class' => 'Item_OperatingSystem',  'table' => 'glpi_items_operatingsystems',   'where' => 'items_id', 'note' => 'Move; dedupe by (itemtype,items_id,operatingsystems_id)'],
            // GLPI 12 has no single glpi_items_devices table; devices are split
            // into per-type detail tables. All are (itemtype, items_id) keyed.
            ['class' => 'Item_DeviceBattery',       'table' => 'glpi_items_devicebatteries',       'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceCamera',        'table' => 'glpi_items_devicecameras',         'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceCase',          'table' => 'glpi_items_devicecases',           'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceControl',       'table' => 'glpi_items_devicecontrols',        'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceDrive',         'table' => 'glpi_items_devicedrives',          'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceFirmware',      'table' => 'glpi_items_devicefirmwares',       'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceGeneric',       'table' => 'glpi_items_devicegenerics',        'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceGraphicCard',   'table' => 'glpi_items_devicegraphiccards',    'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceHardDrive',     'table' => 'glpi_items_deviceharddrives',      'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceMemory',        'table' => 'glpi_items_devicememories',        'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceMotherboard',   'table' => 'glpi_items_devicemotherboards',    'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceNetworkCard',   'table' => 'glpi_items_devicenetworkcards',    'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DevicePci',           'table' => 'glpi_items_devicepcis',            'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DevicePowerSupply',   'table' => 'glpi_items_devicepowersupplies',   'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceProcessor',     'table' => 'glpi_items_deviceprocessors',      'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceSensor',        'table' => 'glpi_items_devicesensors',         'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceSimcard',       'table' => 'glpi_items_devicesimcards',        'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_DeviceSoundCard',     'table' => 'glpi_items_devicesoundcards',      'where' => 'items_id', 'note' => 'Device detail (GLPI 12 split table)'],
            ['class' => 'Item_Disk',              'table' => 'glpi_items_disks',               'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Item_Process',           'table' => 'glpi_items_processes',           'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Item_Environment',       'table' => 'glpi_items_environments',        'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Item_SoftwareVersion',   'table' => 'glpi_items_softwareversions',    'where' => 'items_id', 'note' => 'Move; dedupe by softwareversions_id (largest data set)'],
            ['class' => 'Item_SoftwareLicense',   'table' => 'glpi_items_softwarelicenses',    'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Infocom',                'table' => 'glpi_infocoms',                  'where' => 'items_id', 'note' => 'Move; conflict if target already has financial data'],
            ['class' => 'Contract_Item',          'table' => 'glpi_contracts_items',           'where' => 'items_id', 'note' => 'Move; dedupe by (itemtype,items_id,contracts_id)'],
            ['class' => 'Document_Item',          'table' => 'glpi_documents_items',           'where' => 'items_id', 'note' => 'Move; dedupe by (itemtype,items_id,documents_id)'],
            ['class' => 'NetworkPort',            'table' => 'glpi_networkports',              'where' => 'items_id', 'note' => 'Move; dedupe by (itemtype,items_id,logical_number,name)'],
            ['class' => 'Asset_PeripheralAsset',  'table' => 'glpi_assets_assets_peripheralassets', 'where' => 'items_id_asset', 'note' => 'Move peripheral links; column differs from standard items_id (GLPI 12 table name verified)'],
            ['class' => 'Notepad',                'table' => 'glpi_notepads',                  'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'KnowbaseItem_Item',      'table' => 'glpi_knowbaseitems_items',       'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Item_RemoteManagement',  'table' => 'glpi_items_remotemanagements',   'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'ItemAntivirus',          'table' => 'glpi_itemantiviruses',           'where' => 'items_id', 'note' => 'Move (GLPI 12 table name verified)'],
            ['class' => 'Appliance_Item',         'table' => 'glpi_appliances_items',          'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Domain_Item',            'table' => 'glpi_domains_items',             'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Item_Project',           'table' => 'glpi_items_projects',            'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'ItemVirtualMachine',     'table' => 'glpi_itemvirtualmachines',      'where' => 'items_id', 'note' => 'Move (GLPI 12 table name verified)'],
            ['class' => 'ManualLink',             'table' => 'glpi_manuallinks',               'where' => 'items_id', 'note' => 'Move (GLPI 12 table name verified)'],
            ['class' => 'Socket',                 'table' => 'glpi_sockets',                   'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'ReservationItem',        'table' => 'glpi_reservationitems',          'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Item_Ticket',            'table' => 'glpi_items_tickets',             'where' => 'items_id', 'note' => 'ITIL link — move'],
            ['class' => 'Item_Problem',           'table' => 'glpi_items_problems',            'where' => 'items_id', 'note' => 'ITIL link — move'],
            ['class' => 'Change_Item',            'table' => 'glpi_changes_items',             'where' => 'items_id', 'note' => 'ITIL link — move'],
            ['class' => 'Certificate_Item',       'table' => 'glpi_certificates_items',        'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'DatabaseInstance',       'table' => 'glpi_databaseinstances',         'where' => 'items_id', 'note' => 'Move'],
            ['class' => 'Agent',                  'table' => 'glpi_agents',                    'where' => 'items_id', 'note' => 'Do NOT move: target already owns the agent binding'],
        ];
    }

    /**
     * Field conflict policy (user decision: manual adjudication after preview).
     *
     * Each entry:
     *   field       => glpi_computers column
     *   winner      => 'target' | 'source_if_target_blank' | 'manual'
     *   lock        => whether winner value should be locked after a future merge
     *   reason      => why
     *
     * @return array<string,array{field:string,winner:string,lock:bool,reason:string}>
     */
    public static function fieldPolicies(): array
    {
        return [
            'serial'         => ['field' => 'serial',         'winner' => 'target', 'lock' => false, 'reason' => 'Agent truth — never overwrite'],
            'uuid'           => ['field' => 'uuid',           'winner' => 'target', 'lock' => false, 'reason' => 'Agent truth — never overwrite'],
            'is_dynamic'     => ['field' => 'is_dynamic',     'winner' => 'target', 'lock' => false, 'reason' => 'Target must stay dynamic'],
            'date_creation'  => ['field' => 'date_creation',  'winner' => 'target', 'lock' => false, 'reason' => 'Audit field — keep target'],
            'otherserial'    => ['field' => 'otherserial',    'winner' => 'manual', 'lock' => true,  'reason' => 'Fixed asset number: financial reconciliation key; manual decision always required'],
            'locations_id'   => ['field' => 'locations_id',   'winner' => 'manual', 'lock' => true,  'reason' => 'Manual field; inventory may overwrite — lock required if transferred'],
            'comment'        => ['field' => 'comment',        'winner' => 'manual', 'lock' => true,  'reason' => 'Manual audit note; inventory does not set, but preserve source content'],
            'buy_date'       => ['field' => 'buy_date',       'winner' => 'manual', 'lock' => true,  'reason' => 'Financial field; manual decision required'],
            'states_id'      => ['field' => 'states_id',      'winner' => 'target', 'lock' => false, 'overridable' => true, 'override_lock' => true, 'reason' => 'Current state from inventory is authoritative; operator may elect to override with source value at execution time'],
            'users_id'       => ['field' => 'users_id',       'winner' => 'target', 'lock' => false, 'overridable' => true, 'override_lock' => true, 'reason' => 'Current user from inventory is authoritative; operator may elect to override with source value at execution time'],
            // GLPI 12 groups are rows in glpi_groups_items, not a scalar column.
            // Override means: opt-in to move the source group row(s) to target.
            'groups'         => ['field' => 'groups',         'winner' => 'target', 'lock' => false, 'overridable' => true, 'override_lock' => false, 'relation' => 'glpi_groups_items', 'reason' => 'Group membership lives in glpi_groups_items; operator opts in to adopt source group(s)'],
            'name'           => ['field' => 'name',           'winner' => 'target', 'lock' => false, 'reason' => 'Keep agent-created record name'],
        ];
    }

    /**
     * Values that mean "blank/default" for blank-detection in preview.
     *
     * @return array<string,array<int,string>>
     */
    public static function blankSentinels(): array
    {
        return [
            'otherserial' => ['', 'No Asset Information', 'No asset information'],
            'locations_id' => ['', '0'],
            'comment'      => [''],
            'buy_date'     => ['', '0000-00-00', 'NULL'],
            'serial'       => ['', 'No Serial Number'],
        ];
    }

    /**
     * Fields the operator may optionally override with the source value at
     * execution time (states_id / users_id). Default policy keeps the target
     * (agent truth); the confirmation screen offers an explicit opt-in, and
     * those overrides are always locked (user decision 2026-11-25).
     *
     * @return array<string,array{field:string,label:string,override_lock:bool}>
     */
    public static function overridableFields(): array
    {
        $out = [];
        foreach (self::fieldPolicies() as $key => $policy) {
            if (!empty($policy['overridable'])) {
                $labels = ['states_id' => __('Status', 'assetmerge'), 'users_id' => __('User', 'assetmerge'), 'groups' => __('Group', 'assetmerge')];
                $out[$key] = [
                    'field'         => $policy['field'],
                    'label'         => $labels[$key] ?? $key,
                    'override_lock' => !empty($policy['override_lock']),
                    'relation'      => $policy['relation'] ?? '',
                ];
            }
        }
        return $out;
    }

    /**
     * Fields that would be locked if transferred (user decision #3).
     *
     * @return array<int,string>
     */
    public static function fieldsToLock(): array
    {
        $out = [];
        foreach (self::fieldPolicies() as $field => $policy) {
            if ($policy['lock']) {
                $out[] = $field;
            }
        }
        return $out;
    }
}
