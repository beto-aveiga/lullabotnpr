INTRODUCTION
------------

The NPR module is actually a set of closely-related modules that can push/pull
content to/from the NPR API. The other submodules in this project depend heavily
on npr_api.


REQUIREMENTS
------------

The modules will do ALMOST NOTHING without an NPR API key. Get one at
http://api.npr.org.

In order to ensure proper display of stories, the NPR Story module includes the
NPR Story text format that is preconfigured with the necessary filters and
requires the Allowed Formats module to ensure that this text format is used by
default by the NPR Story body field.


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
