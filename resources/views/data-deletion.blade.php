@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-12">
    <h1 class="text-3xl font-bold mb-6">Veri Silme Talimatları</h1>

    <div class="prose prose-gray max-w-none">
        <h2>Veri Silme Talebi</h2>
        <p>
            PostTimer uygulamasından verilerinizin tamamen silinmesini talep edebilirsiniz.
            Aşağıdaki yöntemlerden birini kullanarak talepte bulunabilirsiniz.
        </p>

        <h2>1. Uygulama İçinden Silme</h2>
        <ol>
            <li>Panele giriş yapın</li>
            <li>Aktif takımınızı (hesabınızı) seçin</li>
            <li>Instagram → Hesaplar sayfasına gidin</li>
            <li>Bağlı hesabınızı silin ("Sil" butonu)</li>
            <li>Takım üyeliğinizi sonlandırın</li>
        </ol>
        <p>Bu işlem hesabınıza ait tüm Instagram verilerini veritabanımızdan kalıcı olarak siler.</p>

        <h2>2. E-posta ile Silme Talebi</h2>
        <p>
            <a href="mailto:yunusemregurlu@gmail.com?subject=Veri Silme Talebi">yunusemregurlu@gmail.com</a>
            adresine aşağıdaki bilgileri içeren bir e-posta gönderin:
        </p>
        <ul>
            <li>Konu: "Veri Silme Talebi"</li>
            <li>Instagram kullanıcı adınız</li>
            <li>Sistemde kayıtlı e-posta adresiniz</li>
        </ul>

        <h2>3. Silinen Veriler</h2>
        <p>Silme talebi uponunda şu veriler kalıcı olarak silinir:</p>
        <ul>
            <li>Instagram hesap kaydınız (ig_user_id, kullanıcı adı, profil bilgileri)</li>
            <li>Şifrelenmiş erişim jetonunuz</li>
            <li>Oluşturduğunuz tüm gönderi kayıtları (taslak, zamanlanmış, yayınlanmış)</li>
            <li>Konteyner ve medya ID'leri</li>
        </ul>

        <h2>4. İşleme Süresi</h2>
        <p>
            Silme talepleri <strong>30 gün içinde</strong> işlenir. İşlem tamamlandığında
            e-posta ile bilgilendirilirsiniz.
        </p>

        <h2>5. Meta Tarafından Tetiklenen Silme</h2>
        <p>
            Instagram hesabınızı Meta platformundan kaldırdığınızda, Meta bir webhook
            göndererek veri silme talebinde bulunur. Bu talep otomatik olarak işlenir ve
            ilgili tüm veriler 7 gün içinde silinir.
        </p>

        <h2>İletişim</h2>
        <p>
            Sorularınız için: <a href="mailto:yunusemregurlu@gmail.com">yunusemregurlu@gmail.com</a>
        </p>
    </div>
</div>
@endsection
