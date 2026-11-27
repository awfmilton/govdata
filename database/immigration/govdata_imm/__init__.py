"""govdata.ca immigration data loader.

Fetches published immigration datasets when they change, archives each download verbatim, and loads it into the
imm_* tables in grantconts with full provenance (imm_editions) and a revision log (imm_fact_revisions).
See database/immigration/README.md.
"""
