import Component from 'flarum/common/Component';
import CommentPost from 'flarum/forum/components/CommentPost';
import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';

class SpotterImageCard extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.id = this.attrs.photoId;
    this.data = null;
    this.showExif = false;
    this.fetchExifData();
  }

  fetchExifData() {
    app.request({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/spotter-image/' + this.id
    }).then(response => {
      this.data = response;
      m.redraw();
    });
  }

  formatDate(dateString) {
    if (!dateString) return '-';
    try {
        const parts = dateString.split(' '); 
        const datePart = parts[0].replace(/:/g, '-');
        const timePart = parts[1] || '';
        const [year, month, day] = datePart.split('-');
        return `${day}-${month}-${year} ${timePart}`;
    } catch (e) { return dateString; }
  }

  view() {
    if (!this.data) return null;
    const exif = JSON.parse(this.data.exif_data || '{}');
    // ... (Buradaki EXIF kartı kodları aynı kalıyor, kısalttım) ...
    const camera = `${exif.make || ''} ${exif.model || ''}`.trim() || 'Belirtilmemiş';
    let lens = exif.lens || 'Belirtilmemiş';
    if (lens !== 'Belirtilmemiş' && camera.includes(lens)) lens = '-';
    const aperture = exif.aperture || '-';
    const exposure = exif.exposure || '-';
    const iso = exif.iso || '-';
    const focal = exif.focal || '-';
    const formattedDate = this.formatDate(exif.date);

    return (
      <div className="SpotterCard-exif-wrapper">
        <div className="SpotterCard-overlay-controls">
             <a href={this.data.path} target="_blank" className="SpotterCard-zoomIcon">Orijinal</a>
        </div>
        <div className="SpotterCard-toggle" onclick={() => this.showExif = !this.showExif}>
            <span className="toggle-label">Fotoğraf Bilgileri (EXIF)</span>
            <i className={this.showExif ? "fas fa-chevron-up" : "fas fa-chevron-down"}></i>
        </div>
        {this.showExif ? (
            <div className="SpotterCard-exif fade-in">
                <div className="SpotterCard-grid">
                    <div className="sc-left-col">
                        <div className="gear-group"><div className="gear-label">Kamera</div><div className="gear-name">{camera}</div></div>
                        <div className="gear-group"><div className="gear-label">Lens</div><div className="lens-name">{lens}</div></div>
                    </div>
                    <div className="sc-right-col">
                        <div className="stat-box"><span>Enstantane</span><b>{exposure}</b></div>
                        <div className="stat-box"><span>Diyafram</span><b>{aperture}</b></div>
                        <div className="stat-box"><span>ISO</span><b>{iso}</b></div>
                        <div className="stat-box"><span>Odak Uzaklığı</span><b>{focal}</b></div>
                    </div>
                    <div className="sc-footer">
                        <div className="date-box"><span>Çekim Tarihi</span><b>{formattedDate}</b></div>
                        {exif.lat && exif.lon ? (<a href={`https://www.google.com/maps?q=${exif.lat},${exif.lon}`} target="_blank" className="map-btn">Haritada Gör</a>) : null}
                    </div>
                </div>
            </div>
        ) : null}
      </div>
    );
  }
}

export default {
  init: () => {
    const mountSpotterImages = function() {
      const postBody = this.element.querySelector('.Post-body');
      if (!postBody) return;

      // 1. YENİ SİSTEM
      const containers = postBody.querySelectorAll('.spotter-image-container');
      containers.forEach(el => {
        const id = el.getAttribute('data-id');
        const exifPlaceholder = el.querySelector('.spotter-exif-placeholder');
        el.classList.add('SpotterCard');
        
        // UX Düzeltmesi: Resme tıklandığında Orijinal boyutu açsın
        const imgLink = el.querySelector('a.spotter-image-link');
        if (imgLink) {
            let originalHref = imgLink.getAttribute('href');
            if (originalHref) {
                originalHref = originalHref.replace('thumb_', '').replace('mini_', '');
                imgLink.setAttribute('href', originalHref);
            }
        }

        if (exifPlaceholder && !exifPlaceholder.hasChildNodes()) {
            m.mount(exifPlaceholder, { view: () => m(SpotterImageCard, { photoId: id }) });
        }
      });

      // 2. ESKİ FOTOĞRAFLAR İÇİN "NÜKLEER" DÜZENLEME
      const contentImages = postBody.querySelectorAll('img');
      
      contentImages.forEach(img => {
          // Eğer bizim eklenti değilse VE emoji değilse VE avatar değilse
          if (!img.closest('.spotter-image-container') && 
              !img.classList.contains('emoji') && 
              !img.classList.contains('Avatar')) {
              
              // RESMİN KENDİSİ
              img.classList.add('spotter-old-image');
              // "display: block" resmin satırı kaplamasını sağlar
              // "margin: 0" tüm dış boşlukları öldürür
              img.style.cssText = "display: block !important; width: 100% !important; margin: 0 !important; padding: 0 !important; border: none !important;";

              // RESMİN ÇEVRESİNDEKİ FAZLALIKLAR (<br> etiketleri)
              if (img.nextSibling && img.nextSibling.tagName === 'BR') {
                 img.nextSibling.style.display = 'none'; // Enter boşluklarını gizle
              }
              if (img.previousSibling && img.previousSibling.tagName === 'BR') {
                 img.previousSibling.style.display = 'none';
              }

              // RESMİN İÇİNDE OLDUĞU PARAGRAF (<p>)
              const parentP = img.closest('p');
              if (parentP) {
                  // Paragrafın yazı boyutunu ve satır yüksekliğini SIFIR yapıyoruz.
                  // Bu, "Ghost Whitespace" denilen gizli boşlukları öldürür.
                  parentP.style.fontSize = "0";
                  parentP.style.lineHeight = "0";
                  parentP.style.margin = "0";
                  parentP.style.padding = "0";
                  
                  // Eğer bu paragrafın altında başka bir resim paragrafı varsa, onunla birleşsin
                  const nextEl = parentP.nextElementSibling;
                  if (nextEl && nextEl.tagName === 'P' && nextEl.querySelector('img')) {
                      parentP.style.marginBottom = "0";
                  }
                  
                  // Eğer üstünde başka bir resim paragrafı varsa
                  const prevEl = parentP.previousElementSibling;
                  if (prevEl && prevEl.tagName === 'P' && prevEl.querySelector('img')) {
                      parentP.style.marginTop = "0";
                  } else {
                      // Eğer üstünde YAZI varsa, yazıya yapışmasın, azıcık boşluk ver
                      // Sadece en üstteki resme margin veriyoruz
                      if (parentP.previousElementSibling) { 
                          parentP.style.marginTop = "10px"; 
                      }
                  }
              }
          }
      });
    };

    extend(CommentPost.prototype, 'oncreate', mountSpotterImages);
    extend(CommentPost.prototype, 'onupdate', mountSpotterImages);
  }
};