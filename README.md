INTRODUCTION
------------

The NPR module is actually a set of closely-related modules that can push/pull
content to/from the NPR API. The other submodules in this project depend heavily
on npr_api.


REQUIREMENTS
------------

The modules will do ALMOST NOTHING without an NPR API key. Get one at
http://api.npr.org


 INSTALLATION
------------

Install as you would normally install a contributed Drupal module.

To be able to interact with the NPR API, the API key must be configured. This
can be done at Administration » Configuration » Web Services » NPR API.
However, for security considerations, this should probably configured in
`settings.php` like this:

```
$config['npr_api.settings']['npr_api_api_key'] = 'YOUR_NPR_API_KEY';
```

To test that the key is configured correctly, visit the "API Test" tab:
admin/config/services/npr/npr_api_test.
