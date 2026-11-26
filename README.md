# Email to Theme

A Mautic 7 plugin that turns an email built with the drag-and-drop (MJML) builder into an
email theme, or updates an existing theme from it.

## Using it

1. Open an email (Channels › Emails › click the email).
2. Click **Save as theme**. The button is only there for emails built with the MJML builder
   and for users who may create or edit themes.
3. Choose:
   - **Create a new theme**: enter a name. The folder name is made from it
     ("Spring Sale" → `themes/spring-sale`).
   - **Update an existing theme**: pick the theme. It keeps its name and folder.
4. Optionally upload a thumbnail (PNG, JPEG, GIF or WebP; converted to PNG, at most 600 px wide).
5. Click **Save theme**. New emails can now start from the theme.

Emails that already use a theme do not change when the theme is updated: Mautic copies a theme
into an email when the email is created.

## What gets written

```
themes/<key>/
  config.json           name, builder grapesjsbuilder, feature email, plus "emailToTheme" (source email, date)
  composer.json
  thumbnail.png         the upload, or a placeholder
  html/email.html.twig  the email's MJML
  html/base.html.twig   standard wrappers (only added on update if missing)
  html/message.html.twig
  assets/               the email's images
```

- **Content**: the MJML is kept exactly as built, including tokens like `{contactfield=firstname}`.
  Text that looks like Twig (`{{`, `{%`, `{#`) is escaped so it comes out unchanged.
- **Images**: images on this Mautic (only from `media/` and `themes/`) are copied from disk.
  Other images are downloaded over http(s) from public addresses only, at most 5 MB each
  (`email_to_theme_max_image_bytes`). Downloads can be switched off with
  `email_to_theme_fetch_remote: false`. SVG is accepted only from this Mautic. The file type is
  read from the bytes. Images that cannot be copied keep their address and are listed after saving.
  In the template, copied images use `getAssetUrl('themes/'~template~'/assets/…')`, as Mautic's own
  themes do.
- **Updating**: the whole theme folder is zipped to `var/email-to-theme-backups/<key>-<date>.zip`
  (outside the web root) first. If the backup fails, nothing is changed. Other files in the theme
  (page templates, other assets) are kept. An image with the same name but different content is saved
  as `name-2.png` and so on, so pages that use the old file keep working.
- **Mautic's own themes** (brienz, blank…) cannot be updated: a Mautic update would overwrite them.

To restore a backup, unzip it into `themes/` (it contains the `<key>/` folder).

## Permissions

- View access to the email (`email:emails:viewown` / `viewother`).
- `core:themes:create` to create a theme, `core:themes:edit` to update one.

## Limits

- Emails without MJML (code mode, the legacy builder) cannot be converted.
- Fonts referenced by URL and images in `srcset` are left as they are.

## Development

Code lives in `Theme/` (`MjmlSource`, `AssetCollector`, `TemplateBuilder`, `ThemeWriter`, `ThemeCatalog`),
the page in `Controller/ConvertController.php` and the button in `EventListener/ButtonSubscriber.php`.

Tests (unit and functional; functional tests write a randomly named theme to the real `themes/`
folder and remove it afterwards):

```
rm -rf var/cache/test          # from the project root
cd docroot/app && ../../vendor/bin/phpunit -c ../plugins/EmailToThemeBundle/phpunit.xml.dist
```
