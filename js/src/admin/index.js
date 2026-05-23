import app from 'flarum/admin/app';
import SpottersSettingsPage from './components/SpottersSettingsPage';

app.initializers.add('spottersturkey-upload-exif', () => {
  app.extensionData
    .for('spottersturkey-upload-exif')
    .registerPage(SpottersSettingsPage);
});