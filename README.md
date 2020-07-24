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

Stories sometimes have external assets embedded in them, such as YouTube videos
and Tweets. The NPR Story module contains a media source to hold these assets
via oEmbed. However, Drupal only enables YouTube and Vimeo as oEmbed providers
out-of-the box, so you will need to enable additional providers yourself. This
can be done through custom code using hook_media_source_info_alter(), or via the
oEmbed Providers module:
https://drupal.org/project/oembed_providers


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


SECURITY CONSIDERATIONS
-----------------------

The body field of the NPR Story content type uses the NPR Story text format,
which allows the `<script>` tag. This was added to accommodate stories that
include "HTML assets" in the body such as:
https://www.npr.org/sections/health-shots/2020/03/16/816707182/map-tracking-the-spread-of-the-coronavirus-in-the-u-s
Consider the security risks to allowing this tags on your site.
