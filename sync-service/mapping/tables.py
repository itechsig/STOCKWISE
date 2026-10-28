"""
Whitelist of Accurate (GDB) tables to mirror into MySQL `stockwise` DB, prefixed
`accurate_` to avoid any collision with Stockwise's own tables (MySQL on this
machine has lower_case_table_names=1 — table names are case-insensitive).

Scope decided 2026-09-11 (user-approved): only tables relevant to Stockwise,
not a full 234-table mirror. See docs/ACCURATE_MAPPING.md for the reasoning
behind each table.
"""

MIRROR_TABLES = [
    # Master data
    "ITEM",
    "WAREHS",
    "ITEMCATEGORY",
    "PERSONDATA",
    "CUSTTYPE",
    # Stock balances / movements
    "ITEMBALANCE",
    "ITEMBALANCEWAREHOUSE",
    "ITEMADJ",
    "ITADJDET",
    "ITEMHIST",
    # Purchasing
    "PO",
    "PODET",
    "REQUISITION",
    "REQUISITIONDET",
    "APINV",
    "APITMDET",
    # Sales
    "SO",
    "SODET",
    "ARINV",
    "ARINVDET",
]

# Mirrored when readable, but a failure to read one of these never fails the
# sync (logged and skipped). DEPARTMENT = names for REQUISITIONDET.DEPTID,
# i.e. the divisi of a PPB (added 2026-09-28).
OPTIONAL_MIRROR_TABLES = [
    "DEPARTMENT",
    # SHIPMENT = names for ARINV.SHIPVIA (NPBG "Klasifikasi") and APINV.SHIPVIA (RI "Pengirim"), added 2026-10-05
    "SHIPMENT",
    # TERMOPMT = ARINV.TERMSID (NPBG template "Tipe NPBG" = Terms); SNHISTORY + SERIALNUMBERS =
    # serial numbers per ITEMHIST row (template SNLine) — raw, for audit (added 2026-10-05)
    "TERMOPMT",
    "SNHISTORY",
    "SERIALNUMBERS",
]

# Not Accurate tables: computed on the staging copy with Accurate's OWN stored
# procedures and pushed like a table (whitelisted the same way). Optional too.
#   SNLINE_BYITEMHIST = VIEW_SN_BYITEMHIST(ITEMHISTID, 0) — the "No. Seri" SNLine
#   exactly as Accurate prints it (its serial order is Firebird's own, so it can't
#   be rebuilt from the raw tables).
DERIVED_TABLES = [
    "SNLINE_BYITEMHIST",
]


def mysql_table_name(accurate_table_name: str) -> str:
    return f"accurate_{accurate_table_name.lower()}"
