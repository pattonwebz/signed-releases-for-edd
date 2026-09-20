=== Signed Releases for EDD ===
Contributors: williampatton
Tags: minisign, code signing, plugin updates, security, easy digital downloads
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serves minisign signatures for Easy Digital Downloads download files, so customer sites can verify a release before WordPress installs it.

== Description ==

Signed Releases for EDD publishes the minisign signatures a customer's site needs in order to verify the release zips it downloads from your store.

It is the store-side half of a three-part arrangement: your release workflow signs each zip, this plugin serves the signature, and a companion client library on the customer's site verifies it before WordPress installs anything. The signing key never touches the store — this plugin only ever handles public material.

**What it does**

* Discovers the `.minisig` produced next to each download file — a local path, a publicly reachable URL, or a file uploaded to the media library — and archives it against that version.
* Injects the archived signature into the Easy Digital Downloads Software Licensing `get_version` API response, so an updating site receives the signature alongside the update offer.
* Exposes a public, read-only endpoint (`?edd_action=get_release_signature`) so a signature can be fetched by item ID and version, or checked by hand.
* Serves a signed revocation manifest (`?edd_action=get_revocation_manifest`) so a store can publish key revocations to sites that pin a revocation root key.
* Adds a status panel to each download showing which version has a signature, the ID of the key that signed it, a manual recheck, and automatic retries when a signature has not been uploaded yet.

**Requirements**

* Easy Digital Downloads.
* Easy Digital Downloads Software Licensing, for the `get_version` API injection.
* PHP 7.4 or later.

**Sanity checks that make verification usable**

A missing signature is treated as strictly as an invalid one, so a version with no signature archived here cannot be verified by a client. The download's status panel tells you which versions are covered, and the `.minisig` from a release workflow can be uploaded at any time — the plugin will pick it up on the next check.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/signed-releases-for-edd`, or install it through the Plugins screen.
2. Activate the plugin.
3. For each release, upload the `.minisig` file produced by your signing workflow next to the matching download file — or into the media library if the file is stored somewhere you cannot write to.
4. Open the download's edit screen and use the signature panel: it shows the version discovered, the signing key ID, and a "check now" action.
5. Optionally enter the plugin slug for the download, so the public endpoint can also resolve a signature by slug.

== Frequently Asked Questions ==

= Does this work without Easy Digital Downloads? =

No. The plugin bails early and shows an admin notice when Easy Digital Downloads is not active, and the `get_version` injection requires Software Licensing.

= Where do the signatures come from? =

From your own release workflow. Sign each release zip with minisign, then upload the resulting `<file>.minisig` next to the download file. The plugin only reads and republishes those files; it never holds a private key.

= Does it touch customer orders or licences? =

No. It reads and archives the signature files attached to a download, and adds a read-only field to the update API response. Orders, licences and customer records are untouched.

= What happens on a site if a signature is missing? =

The companion client library fails closed: in enforce mode the update is refused, and in log mode it is logged for review. This plugin's job is to make sure the signature is available in the first place.

= Does it send anything to the plugin author? =

No. There is no telemetry, no phoning home, and no service the plugin author operates.

== External services ==

This plugin makes no requests to the plugin author or to any service operated by the plugin author. It contacts the following, all of which belong to your own store:

* **Your download files' host.** When a download's file is stored offsite, the plugin requests the signature at the same URL with `.minisig` appended, so it can be archived. Only that URL is requested.
* **Your store's public signature endpoint** (`?edd_action=get_release_signature`), served by this plugin. It returns the archived `.minisig` for an item ID and version. Read-only, requires no authentication, and returns public signature data only.
* **Your store's public revocation manifest endpoint** (`?edd_action=get_revocation_manifest`), served by this plugin. It returns the manifest you signed offline with your revocation root key. Read-only.

No customer, order, licence or site data is included in any of these requests or responses.

== Changelog ==

= 0.1.0 =
* Initial release: signature discovery and archiving, Software Licensing `get_version` injection, public signature and revocation-manifest endpoints, download status panel.
