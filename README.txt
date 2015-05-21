Flysystem Dropbox
=================

For setup instructions see the Flysystem README.txt.

## CONFIGURATION ##

Example configuration:

$schemes = [
  'dropboxexample' => [
    'type' => 'dropbox',
    'config' => [
      'token' => 'a-long-token-string',
      'client_id' => 'You Client Id Name',

      // Optional.
      'prefix' => 'a/sub/directory',
    ],
  ],
];

$settings['flysystem'] = $schemes;
