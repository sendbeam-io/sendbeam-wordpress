# WordPress.org listing assets

These files are **not** part of the plugin. They are the images the Plugin
Directory shows on the listing page, and they live in the `assets/` directory
of the plugin's SVN repository — a sibling of `trunk/` and `tags/`, never
inside them. `.distignore` keeps this folder out of the distributed zip.

| File | Where it appears |
| --- | --- |
| `icon-256x256.png` | The plugin icon, in search results and the installer |
| `icon.svg` | The same mark as vector, preferred where it is supported |
| `banner-772x250.png` | The header of the listing page |
| `banner-1544x500.png` | The same, on high-density screens |
| `screenshot-1.png` … `screenshot-9.png` | The Screenshots section, captioned in order by `readme.txt` |

Captions come from the `== Screenshots ==` list in `readme.txt`: the *n*th line
captions `screenshot-n.png`. Adding a screenshot means adding both.

Screenshots are 1280x800 at 1x, taken on a real site running the plugin
against a live SendBeam workspace. Nothing in them is seeded, mocked or
blurred: the only editing is that other plugins' admin notices are hidden, so
each one shows this plugin rather than somebody else's nag.
