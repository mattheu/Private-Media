=== Private Media ===
Contributors: mattheu
Tags: private, media, files
Requires at least: 4.5
Tested up to: 4.7
Stable tag: 1.0.6

Make files in the WordPress media library private. These are only accessible to logged in users.

REQUIRES: HM Core.

== Description ==

Private files are moved to a obsfucated location. Access can be completely restricted with .htaccess/nginx. URLs to private attachments are rewritten and the true location is not visible. Requests to these files hit a php script which authenticates the request and returns the file.

Non-private files are not affected.

Private attachments are not visible by default in the media library. Options are provided to filter results by private or public.

Access to Private files attachment pages in the front end is restricted. Will return 404 for logged out users.

== Installation ==

* Install & Activate the plugin.
* Edit an attachment, and check the private files option to set an attachment as private.

== Changelog ==

= 1.0.6 =
* WordPress 4.7 compatibility
* Fix the rewrite rule to use the correct base url
