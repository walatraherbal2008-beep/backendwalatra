@extends('seller.layout', ['title' => 'Daftar Toko | Walatra Herbal', 'registrationLayout' => true])

@section('content')
    <div class="sr-registration">
        <aside class="sr-guide" aria-label="Panduan pendaftaran toko">
            <div class="sr-guide-intro">
                <p class="sr-guide-eyebrow">Mitra Walatra Herbal</p>
                <h2>Siapkan toko Anda di Walatra.</h2>
                <p>Lengkapi akun pengelola, identitas toko, dan alamat untuk mengajukan toko Anda.</p>
            </div>
            <ol class="sr-progress" aria-label="Tahap pendaftaran">
                @foreach([['Akun', 'Email dan akses pengelola toko'], ['Identitas toko', 'Nama dan informasi toko Anda'], ['Alamat', 'Wilayah dan alamat lengkap toko']] as $index => [$label, $description])
                    <li data-step-indicator="{{ $index + 1 }}" data-state="{{ $index === 0 ? 'active' : 'upcoming' }}">
                        <button type="button" data-jump-step="{{ $index + 1 }}" @disabled($index !== 0)>
                            <span class="sr-step-marker" aria-hidden="true"><span data-step-number>{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span><svg data-step-check hidden viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="sr-step-copy"><strong>{{ $label }}</strong><span>{{ $description }}</span><span class="sr-step-state" data-step-status>{{ $index === 0 ? 'Sedang diisi' : 'Belum diisi' }}</span></span>
                        </button>
                    </li>
                @endforeach
            </ol>
            <div class="sr-guide-note"><h3>Setelah pengajuan dikirim</h3><p>Verifikasi email Anda. Tim Walatra akan memeriksa pengajuan sebelum toko dapat beroperasi.</p></div>
        </aside>

        <div class="sr-form-panel">
            <header class="sr-form-header">
                <p class="sr-eyebrow" id="registration-progress-label" aria-live="polite">Langkah 1 dari 3</p>
                <h1>Daftarkan toko Anda</h1>
                <p>Isi data secara bertahap, lalu periksa kembali sebelum mengirim pengajuan.</p>
            </header>
            <form method="POST" action="{{ route('seller.register.store') }}" id="seller-registration-form"
                data-locations-url="{{ url('/api/v1/shipping/locations') }}"
                data-initial-step="{{ $errors->hasAny(['province', 'province_id', 'city', 'city_id', 'district', 'district_id', 'sub_district_id', 'sub_district_name', 'postal_code', 'address']) ? 3 : ($errors->hasAny(['store_name', 'description', 'contact_phone']) ? 2 : 1) }}">
                @csrf
                <noscript><p class="sr-error-summary">Aktifkan JavaScript untuk mengisi pendaftaran toko dan memilih alamat.</p></noscript>
                @if($errors->any())<div class="sr-error-summary" role="alert">Pengajuan belum dikirim. Periksa kembali data yang ditandai.</div>@endif
                <section class="registration-step sr-step" data-step="1" aria-labelledby="account-heading">
                    <div class="sr-section-heading"><h2 id="account-heading" tabindex="-1">Akun pengelola toko</h2><p>Gunakan email yang dapat Anda akses untuk menerima verifikasi.</p></div>
                    <div class="sr-field">
                        <label for="name">Nama lengkap</label>
                        <input id="name" name="name" required maxlength="255" autocomplete="name" value="{{ old('name', $user->name) }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')<p class="sr-field-error" id="name-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sr-field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" required maxlength="255" autocomplete="email" value="{{ old('email', $user->email) }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        @error('email')<p class="sr-field-error" id="email-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sr-field">
                        <label for="password">Password</label>
                        <div class="sr-password-field"><input id="password" name="password" type="password" minlength="6" required autocomplete="new-password" aria-describedby="password-help @error('password') password-error @enderror" @error('password') aria-invalid="true" @enderror><button type="button" id="toggle-password" aria-controls="password" aria-pressed="false">Tampilkan</button></div>
                        <p class="sr-field-help" id="password-help">Gunakan minimal 6 karakter.</p>
                        @error('password')<p class="sr-field-error" id="password-error">{{ $message }}</p>@enderror
                    </div>
                </section>
                <section class="registration-step sr-step hidden" data-step="2" aria-labelledby="store-heading">
                    <div class="sr-section-heading"><h2 id="store-heading" tabindex="-1">Identitas toko</h2><p>Informasi ini membantu tim Walatra mengenali toko Anda.</p></div>
                    <div class="sr-field"><label for="store_name">Nama toko</label><input id="store_name" name="store_name" required maxlength="255" value="{{ old('store_name') }}" @error('store_name') aria-invalid="true" aria-describedby="store-name-error" @enderror>@error('store_name')<p class="sr-field-error" id="store-name-error">{{ $message }}</p>@enderror</div>
                    <div class="sr-field"><label for="description">Deskripsi toko <span>Opsional</span></label><textarea id="description" name="description" rows="3" maxlength="5000" placeholder="Ceritakan secara singkat tentang toko Anda.">{{ old('description') }}</textarea>@error('description')<p class="sr-field-error">{{ $message }}</p>@enderror</div>
                    <div class="sr-field"><label for="contact_phone">Nomor kontak toko <span>Opsional</span></label><input id="contact_phone" name="contact_phone" type="tel" maxlength="20" autocomplete="tel" value="{{ old('contact_phone', $user->phone) }}">@error('contact_phone')<p class="sr-field-error">{{ $message }}</p>@enderror</div>
                </section>
                <section class="registration-step sr-step hidden" data-step="3" aria-labelledby="address-heading">
                    <div class="sr-section-heading"><h2 id="address-heading" tabindex="-1">Alamat toko</h2><p>Pilih wilayah secara berurutan. Kode pos akan mengikuti kelurahan atau desa Anda.</p></div>
                    <div class="sr-address-grid">
                        @foreach(['province' => 'Provinsi', 'city' => 'Kota/Kabupaten', 'district' => 'Kecamatan', 'sub_district' => 'Kelurahan/Desa'] as $field => $label)
                            @php($nameField = $field === 'sub_district' ? 'sub_district_name' : $field)
                            <div class="sr-field">
                                <label for="{{ $field }}_id">{{ $label }}</label>
                                <select id="{{ $field }}_id" name="{{ $field }}_id" required disabled data-location-select="{{ $field }}" data-selected="{{ old($field.'_id') }}"
                                    aria-describedby="location-status @error($field.'_id') {{ $field }}-error @enderror" @error($field.'_id') aria-invalid="true" @enderror>
                                    <option value="">{{ $field === 'province' ? 'Memuat provinsi…' : 'Pilih wilayah sebelumnya' }}</option>
                                </select>
                                <input type="hidden" name="{{ $nameField }}" value="{{ old($nameField) }}" data-location-name="{{ $field }}">
                                @error($field.'_id')<p class="sr-field-error" id="{{ $field }}-error">{{ $message }}</p>@enderror
                                @error($nameField)<p class="sr-field-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="sr-location-feedback"><p id="location-status" role="status" aria-live="polite" class="sr-field-help"></p><button type="button" id="retry-locations" hidden class="sr-button sr-button-secondary">Coba lagi</button></div>
                    <div class="sr-field sr-postal-field"><label for="postal_code">Kode pos</label><input id="postal_code" name="postal_code" value="{{ old('postal_code') }}" required readonly maxlength="10" autocomplete="postal-code">@error('postal_code')<p class="sr-field-error">{{ $message }}</p>@enderror</div>
                    <div class="sr-field"><label for="address">Alamat lengkap</label><textarea id="address" name="address" rows="3" maxlength="5000" autocomplete="street-address" placeholder="Nama jalan, nomor bangunan, RT/RW, dan patokan bila diperlukan.">{{ old('address') }}</textarea>@error('address')<p class="sr-field-error">{{ $message }}</p>@enderror</div>
                </section>
                <section id="registration-review" class="sr-review hidden" aria-labelledby="review-heading">
                    <div class="sr-section-heading"><h2 id="review-heading" tabindex="-1">Periksa pengajuan Anda</h2><p>Pastikan data di bawah sudah benar. Anda masih dapat mengubahnya.</p></div>
                    @foreach([
                        ['Akun pengelola', 1, ['name' => 'Nama lengkap', 'email' => 'Email']],
                        ['Identitas toko', 2, ['store_name' => 'Nama toko', 'description' => 'Deskripsi', 'contact_phone' => 'Kontak toko']],
                        ['Alamat toko', 3, ['address' => 'Alamat lengkap', 'sub_district_name' => 'Kelurahan/Desa', 'district' => 'Kecamatan', 'city' => 'Kota/Kabupaten', 'province' => 'Provinsi', 'postal_code' => 'Kode pos']],
                    ] as [$group, $step, $fields])
                        <div class="sr-review-group">
                            <div class="sr-review-group-heading"><h3>{{ $group }}</h3><button type="button" data-edit-step="{{ $step }}" aria-label="Ubah {{ strtolower($group) }}">Ubah</button></div>
                            <dl>@foreach($fields as $field => $label)<div><dt>{{ $label }}</dt><dd data-review-field="{{ $field }}">—</dd></div>@endforeach</dl>
                        </div>
                    @endforeach
                    <p class="sr-review-note">Setelah mengirim pengajuan, verifikasi email Anda dan tunggu pemeriksaan dari tim Walatra.</p>
                </section>
                <div class="sr-form-actions"><button type="button" id="previous-step" class="sr-button sr-button-secondary hidden">Kembali</button><button type="button" id="next-step" class="sr-button sr-button-primary">Lanjut</button><button type="submit" id="submit-registration" class="sr-button sr-button-primary hidden">Kirim pengajuan toko</button></div>
            </form>
        </div>
    </div>
@endsection
