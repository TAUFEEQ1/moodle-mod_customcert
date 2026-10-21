# Package Usage

To package your Moodle Custom Certificate QR Code Element plugin for distribution:

```bash
./package.sh
```

This will create a zip file named `customcertelement_cqrcode_v[version].zip` that contains:

- `cqrcode/` folder with all necessary plugin files
- Proper directory structure for Moodle installation
- All required files: version.php, classes/, lang/, etc.
- Excludes development files (.vscode, README.md, etc.)

## Installation for end users

1. Extract the zip file
2. Copy the `cqrcode` folder to: `[moodle]/mod/customcert/element/`
3. Visit Site Administration > Notifications to complete installation

The plugin will then be available as a QR Code element type in the Custom Certificate module.
