<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Accurate sync orchestration (docs/sync-architecture.md)
    |--------------------------------------------------------------------------
    | Laravel never connects to Firebird directly, and never shells out to a
    | local process either — the sync-service/ Python Agent lives at the
    | office (holds the real Firebird credentials in its own .env), restores
    | the newest stable .GBK backup to a local staging Firebird DB, and PUSHES
    | the mirrored rows here over HTTPS (POST /api/agent/sync/...), on its own
    | 04:00/10:30/20:30 schedule. Laravel only ever receives data, matches it
    | against Stockwise's own tables, and upserts — see
    | AccurateSyncService::finishFromStaging().
    */

    /*
    |--------------------------------------------------------------------------
    | Accurate table mirror whitelist
    |--------------------------------------------------------------------------
    | The only tables the ingest endpoint (App\Http\Controllers\Api\Agent\
    | AccurateIngestController) will ever create/replace in MySQL. Must stay
    | identical to sync-service/mapping/tables.py's MIRROR_TABLES — enforced
    | by tests/Feature/Agent/AccurateIngestTest.php — so a compromised or
    | buggy Agent can never make Laravel create arbitrary tables.
    */
    'mirror_tables' => [
        'ITEM', 'WAREHS', 'ITEMCATEGORY', 'PERSONDATA', 'CUSTTYPE',
        'ITEMBALANCE', 'ITEMBALANCEWAREHOUSE', 'ITEMADJ', 'ITADJDET', 'ITEMHIST',
        'PO', 'PODET', 'REQUISITION', 'REQUISITIONDET', 'APINV', 'APITMDET',
        'SO', 'SODET', 'ARINV', 'ARINVDET',
        // OPTIONAL_MIRROR_TABLES in tables.py (a failed read never fails the sync)
        'DEPARTMENT', 'SHIPMENT', 'TERMOPMT', 'SNHISTORY', 'SERIALNUMBERS',
        // DERIVED_TABLES in tables.py (computed on the staging copy by Accurate's own procedures)
        'SNLINE_BYITEMHIST',
    ],

    /*
    |--------------------------------------------------------------------------
    | Column type vocabulary allowed in an ingest payload
    |--------------------------------------------------------------------------
    | Mirrors sync-service/mapping/type_mapping.py's own output vocabulary.
    | StagingTableWriter refuses any column whose declared type isn't in this
    | list — the Agent sends structured {name, type, length, nullable}, never
    | raw SQL, so this whitelist is what keeps a network payload from ever
    | being able to inject arbitrary DDL.
    */
    'column_types' => [
        // DECIMAL = Firebird NUMERIC/DECIMAL(p,s) (Accurate's quantities, prices, ratios: NUMERIC(18,4))
        'SMALLINT', 'INT', 'BIGINT', 'FLOAT', 'DOUBLE', 'DECIMAL',
        'DATE', 'TIME', 'DATETIME',
        'VARCHAR', 'CHAR', 'TEXT', 'LONGTEXT', 'LONGBLOB',
    ],

];
