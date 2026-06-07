# NPX Menu Icons — Developer Documentation

## Overview

Adds Material Symbols (Google Fonts) icons to WordPress navigation menu items. Icons are configured per menu item through a modal picker injected into the nav-menus admin screen. On the frontend an `<span>` with the icon glyph is prepended or appended to the menu item title.

---

## File Structure

```
npx-menu-icons/
├── npx-menu-icons.php          Main plugin file — hooks, helpers, save logic
├── uninstall.php               Cleanup on plugin deletion
├── includes/
│   ├── class-walker.php        Custom Walker_Nav_Menu_Edit extension
│   └── material-icons.json     Bundled icon data (name + category per icon)
└── assets/
    ├── js/
    │   └── admin.js            Icon picker modal — vanilla ES5 + jQuery
    └── css/
        ├── admin.css           Admin modal styles
        └── frontend.css        Frontend icon alignment styles
```

---

## How It Works

### Admin — Injecting the Button

`wp_edit_nav_menu_walker` filter (priority 99) replaces the default walker with `NPX_Menu_Icons_Walker`. The walker's `start_el()` calls `parent::start_el()` to get the standard HTML, then uses `preg_replace` to inject an icon button **before** `<div class="menu-item-handle">` — placing it in the handle bar row, outside the accordion.

The button shows:
- A faint `add_circle` glyph when no icon is set
- The actual icon glyph (with saved style/fill/weight) when an icon is set

### Admin — Hidden Inputs

`wp_nav_menu_item_custom_fields` action outputs a hidden `<div class="npx-mi-inputs">` inside each menu item accordion containing eight `<input type="hidden">` fields:

| Input class | Name | Description |
|---|---|---|
| `npx-mi-inp-name` | `npx_menu_icon[ID][name]` | Icon name (e.g. `home`) |
| `npx-mi-inp-style` | `npx_menu_icon[ID][style]` | `material-symbols-sharp` or `material-symbols-rounded` |
| `npx-mi-inp-fill` | `npx_menu_icon[ID][fill]` | `0` (outline) or `1` (filled) |
| `npx-mi-inp-weight` | `npx_menu_icon[ID][weight]` | `100`–`700` |
| `npx-mi-inp-position` | `npx_menu_icon[ID][position]` | `before` or `after` |
| `npx-mi-inp-size` | `npx_menu_icon[ID][size]` | CSS size string e.g. `1.5em`, `24px`, `1.25rem` |
| `npx-mi-inp-color` | `npx_menu_icon[ID][color]` | CSS color string, or empty for `currentColor` |
| `npx-mi-inp-hide` | `npx_menu_icon[ID][hide_label]` | `1` to hide label, `0` to show |

The modal JS reads and writes these inputs; they are submitted with the standard WP "Save Menu" POST.

### Admin — Modal

Built as a static DOM fragment appended to `<body>` once on page load. Key sections:

- **Toolbar**: search, category select, style/fill/weight selects, Reset button
- **Left panel**: scrollable icon grid (lazy-loaded in chunks of 150)
- **Right sidebar**: icon preview, position, size (value + unit), color swatches, hide-label toggle
- **Footer**: icon count + Material Symbols link, Refresh button, Cancel / Remove Icon / Apply

#### Icon Grid

Icons are filtered client-side. The first 150 matching icons are rendered; further chunks are appended on scroll (infinite scroll via direct `scroll` event on `.npx-im-grid` — delegated binding does not work because `scroll` does not bubble).

#### Color Swatches

- First swatch: `currentColor` (stored as empty string `""`)
- Theme palette swatches: populated from `npxMenuIconsData.themeColors` (empty array when `npx_color_config()` is not available)
- Custom "+" swatch: reveals a native `<input type="color">` + text field for arbitrary CSS color values

### Admin — Saving

`wp_update_nav_menu_item` action (priority 10) validates and saves data to `_npx_menu_icon` post meta. All fields are whitelist-validated or sanitized. Icon name must match `/^[a-z0-9_]+$/`. Size is clamped: `0.5–4` for `em`/`rem`, `8–128` for `px`. Color passes through `npx_mi_sanitize_color()`.

### Frontend

`nav_menu_item_title` filter (priority 10) reads `_npx_menu_icon` post meta and wraps the title in:

```html
<span class="npx-menu-icon material-symbols-sharp" aria-hidden="true" style="…">icon_name</span>
<span class="npx-menu-icon-label">Menu Item Title</span>
```

(or reversed for `position = after`). When "Hide label" is on, the label span also carries `screen-reader-text` (WP core class).

The font and frontend CSS are only enqueued when at least one `_npx_menu_icon` meta row exists in the database — avoiding unnecessary HTTP requests on sites with no icons set.

---

## Meta Storage

**Meta key**: `_npx_menu_icon`  
**Post type**: `nav_menu_item`

Stored as a serialized PHP array:

```php
array(
    'name'       => 'home',
    'style'      => 'material-symbols-sharp',  // or material-symbols-rounded
    'fill'       => '0',                        // '0' or '1'
    'weight'     => '400',                      // '100'–'700'
    'position'   => 'before',                   // 'before' or 'after'
    'size'       => '1.5em',                    // e.g. '1.5em', '24px', '1.25rem'
    'color'      => '',                         // CSS color or '' for currentColor
    'hide_label' => '0',                        // '0' or '1'
)
```

---

## Icon Data

**Location**: `includes/material-icons.json`  
**Format**: JSON array of `{ "n": "icon_name", "c": "category_slug" }` objects  
**Source**: Google Fonts Material Symbols metadata API

The file is read with a 24-hour transient cache (`npx_mi_icons`). The transient is busted automatically after a successful refresh.

### Refreshing Icons

The Refresh button in the modal footer fires an AJAX request to `npx_mi_ajax_refresh_icons`:

1. Fetches `https://fonts.google.com/metadata/icons?incomplete=1&key=material_symbols`
2. Strips the XSSI prefix (`substr($raw, 5)`)
3. Deduplicates by icon name
4. Normalises category slugs (e.g. `av` → `audio_video`)
5. Writes to `includes/material-icons.json` via `WP_Filesystem`
6. Busts the transient
7. Reloads the page (so PHP serves fresh JSON via `wp_localize_script`)

Requires `manage_options` capability.

---

## Constants

| Constant | Value | Description |
|---|---|---|
| `NPX_MENU_ICONS_VERSION` | `1.0.0` | Plugin version, used as asset cache-busting suffix |
| `NPX_MENU_ICONS_FILE` | `__FILE__` | Absolute path to main plugin file |
| `NPX_MENU_ICONS_DIR` | `plugin_dir_path()` | Absolute path to plugin directory (trailing slash) |
| `NPX_MENU_ICONS_URL` | `plugin_dir_url()` | URL to plugin directory (trailing slash) |
| `NPX_MENU_ICONS_META_KEY` | `_npx_menu_icon` | Post meta key for icon data |
| `NPX_MENU_ICONS_FONT_URL` | Google Fonts URL | CDN URL for Material Symbols Rounded + Sharp |

---

## Filters & Hooks

### `npx_menu_icons_data_file`
Filter the path to the icon JSON file.

```php
add_filter( 'npx_menu_icons_data_file', function ( $path ) {
    return '/custom/path/to/icons.json';
} );
```

### Standard WP hooks used

| Hook | Priority | Description |
|---|---|---|
| `wp_edit_nav_menu_walker` | 99 | Replaces default walker with NPX_Menu_Icons_Walker |
| `admin_enqueue_scripts` | default | Enqueues admin assets on `nav-menus.php` |
| `wp_nav_menu_item_custom_fields` | 10 | Outputs hidden inputs |
| `wp_update_nav_menu_item` | 10 | Saves icon meta |
| `wp_enqueue_scripts` | default | Conditionally enqueues frontend assets |
| `nav_menu_item_title` | 10 | Injects icon span into menu item title |
| `wp_ajax_npx_mi_refresh_icons` | — | AJAX: refresh icon data from Google |

---

## CSS Classes (Frontend)

| Class | Element | Description |
|---|---|---|
| `.npx-menu-icon` | `<span>` | The icon glyph |
| `.material-symbols-sharp` | on icon span | Sharp variant |
| `.material-symbols-rounded` | on icon span | Rounded variant |
| `.npx-menu-icon-label` | `<span>` | Wrapper for the menu item label text |
| `.npx-menu-icon-label.screen-reader-text` | on label span | Applied when "Hide label" is on |

---

## CSS Classes (Admin Modal)

| Class | Description |
|---|---|
| `.npx-mi-handle-btn` | Icon button in the handle bar |
| `.npx-mi-handle-btn.has-icon` | Button when an icon is set |
| `#npxIconModal` | Modal overlay |
| `.npx-im-wrap` | Modal panel container |
| `.npx-im-toolbar` | Search + filter row |
| `.npx-im-grid` | Scrollable icon grid |
| `.npx-im-item` | Individual icon tile |
| `.npx-im-item.is-selected` | Selected icon tile |
| `.npx-im-sidebar` | Right settings panel |
| `.npx-im-sel-glyph` | Preview glyph in sidebar |
| `.npx-im-toggle` | Hide-label toggle switch |
| `.npx-im-swatch` | Color swatch button |
| `.npx-im-swatch--cc` | currentColor swatch |
| `.npx-im-swatch--custom` | Custom color swatch |

---

## JS Data (`npxMenuIconsData`)

Passed via `wp_localize_script` on `nav-menus.php`:

```js
{
    icons:       [ { n: 'icon_name', c: 'category_slug' }, ... ],
    themeColors: [ { name: 'Primary', slug: 'primary', color: '#005CB9' }, ... ],
    ajaxUrl:     'https://example.com/wp-admin/admin-ajax.php',
    nonce:       '<nonce_value>'
}
```

`themeColors` is populated from `npx_color_config()` (NPX theme). When that function is unavailable (non-NPX theme), the array is empty and only the currentColor swatch and the custom picker are shown.

---

## Theme Dependency

| Feature | Dependency | Fallback |
|---|---|---|
| Icon JSON | `includes/material-icons.json` (bundled) | Falls back to `get_template_directory()/includes/data/material-icons.json` |
| Theme colors in picker | `npx_color_config()` function | Only currentColor + custom picker |
| Font deduplication | Same `npx-material-symbols` handle as theme | Works independently — WP deduplicates only if theme also enqueues it |

---

## Security

- AJAX handler checks `manage_options` capability **before** nonce verification
- Icon name validated against `/^[a-z0-9_]+$/`
- All meta fields whitelist-validated or sanitized before saving
- Color sanitized via `npx_mi_sanitize_color()` — allows only CSS-safe characters
- `check_admin_referer()` on save; `check_ajax_referer()` on AJAX
- AJAX response returns only `count` integer, not the full icons array

---

## Accessibility

- Modal: `role="dialog"`, `aria-modal="true"`, `aria-labelledby="npx-im-title"`
- Icon glyph spans: `aria-hidden="true"` (decorative)
- Grid items: `role="option"`, `tabindex="0"`, `aria-selected`
- Grid keyboard: Space/Enter to select; Enter again on selected item to apply
- Toggle: `role="switch"`, `aria-checked`, `aria-label="Hide menu item label"`
- Hidden label: `screen-reader-text` class (WP core definition)

---

## Uninstall

`uninstall.php` deletes all `_npx_menu_icon` post meta rows via `$wpdb->delete()` when the plugin is deleted from the WordPress admin.
