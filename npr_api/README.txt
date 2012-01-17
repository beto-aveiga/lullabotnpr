This module will be required for any API access.

For development purposes, we use the API key for PI's current production access.  This is bad, we know, but it's what we have.

API_Key = MDAxODMxMjE5MDEyMjU5MDc2NDdkMTFjZg001

Website-level API Key = MDA1MjcyMDE0MDEyNzUzOTU1NTMzNmE5NQ010

The website-level is more powerful (e.g., can access AP stories) and is the one used for ingest etc.  This key is set in the pi_npr_ingest.install file.


DMM CHANGES TO ABSTRACTIZE
____________________________

npr_api (formerly pi_npr_api)


Kill getters

_pi_npr_api_get_api_key()
_pi_npr_api_get_org_id()
_pi_npr_api_get_ingest_url()
_pi_npr_api_get_retrieval_url()
_pi_npr_api_get_retrieval_test_id()
pi_npr_api_get_query_ids()
pi_npr_api_get_default_category()

Created standalone callback for config
  npr_api_config_form()

Created standalone hook_menu (no longer a menu alter to pi_utils)

Added hook_permission

Simplified var names

Genercize the the #options => $node_types

ingest-able ==> ingestible

Removed category stuff (or move it to hull and/or retrieval)

query id stuff --> move to retrieval

top stories --> move to retrieval

content page stuff --> move to retrieval

Move test stuff to retrieve

npr_programs_get_url -- seems OK to kill

pi_npr_api_get_xml_attribute -- We don't need this!

npr_api_fetch_feed is now npr_api_fetch_data



TODO:
MOVE ingest type stuff into a hook_menu_alter()

set vars at INSTALL
or actually just do constants
look at json as default


