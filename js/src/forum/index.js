import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import TextEditor from 'flarum/common/components/TextEditor';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import UploadButton from './components/UploadButton';
import GalleryButton from './components/GalleryButton';
import UserUploadsPage from './components/UserUploadsPage'; // Yeni Sayfa
import SpotterImageManager from './components/SpotterImageManager';

app.initializers.add('spottersturkey-upload-exif', () => {
  
  // 1. ROTA TANIMLA (/u/kullaniciadi/uploads)
  app.routes['user.uploads'] = { path: '/u/:username/uploads', component: UserUploadsPage };

  // 2. EDİTÖR BUTONLARI
  extend(TextEditor.prototype, 'toolbarItems', function(items) {
    items.add('spotter-upload', <UploadButton editor={this} />, 102);
    items.add('spotter-gallery', <GalleryButton editor={this} />, 101);
  });

  // 3. PROFİL SAYFASI MENÜSÜNE EKLE
  extend(UserPage.prototype, 'navItems', function(items) {
    const user = this.user;
    items.add(
      'uploads',
      <LinkButton
        href={app.route('user.uploads', { username: user.slug() })}
        icon="fas fa-camera"
      >
        {/* İSİM GÜNCELLENDİ */}
        Kullanıcı Medyası
      </LinkButton>,
      10 // Sıralama puanı
    );
  });
  
  SpotterImageManager.init();
});