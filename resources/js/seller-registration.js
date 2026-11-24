const form = document.querySelector('#seller-registration-form');

if (form) {
    const steps = [...form.querySelectorAll('.registration-step')];
    const indicators = [...document.querySelectorAll('[data-step-indicator]')];
    const next = form.querySelector('#next-step');
    const previous = form.querySelector('#previous-step');
    const submit = form.querySelector('#submit-registration');
    const review = form.querySelector('#registration-review');
    const progressLabel = document.querySelector('#registration-progress-label');
    const status = form.querySelector('#location-status');
    const retry = form.querySelector('#retry-locations');
    const postalCode = form.querySelector('#postal_code');
    const initialPostalCode = postalCode.value;
    const levels = [
        ['province', 'provinces', 'Provinsi'],
        ['city', 'cities', 'Kota/Kabupaten'],
        ['district', 'districts', 'Kecamatan'],
        ['sub_district', 'sub-districts', 'Kelurahan/Desa'],
    ].map(([key, path, label]) => ({
        key, path, label,
        select: form.querySelector(`[data-location-select="${key}"]`),
        name: form.querySelector(`[data-location-name="${key}"]`),
        controller: null,
    }));
    let current = Number(form.dataset.initialStep) || 1;
    let furthest = current;
    let reviewing = false;
    let editing = false;
    let failedLevel = null;
    form.noValidate = true;

    function renderStep() {
        steps.forEach((step, index) => step.classList.toggle('hidden', reviewing || index + 1 !== current));
        review.classList.toggle('hidden', !reviewing);
        indicators.forEach((item, index) => {
            const active = !reviewing && index + 1 === current;
            const complete = reviewing || (!active && (editing || index + 1 < furthest));
            item.dataset.state = active ? 'active' : (complete ? 'complete' : 'upcoming');
            item.querySelector('[data-step-number]').hidden = complete;
            item.querySelector('[data-step-check]').toggleAttribute('hidden', !complete);
            item.querySelector('[data-step-status]').textContent = active ? 'Sedang diisi' : (complete ? 'Selesai' : 'Belum diisi');
            const button = item.querySelector('button');
            button.disabled = index + 1 > furthest;
            if (active) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
        progressLabel.textContent = reviewing ? 'Periksa pengajuan' : `Langkah ${current} dari ${steps.length}`;
        previous.classList.toggle('hidden', current === 1 && !reviewing && !editing);
        next.classList.toggle('hidden', reviewing);
        next.textContent = editing ? 'Simpan perubahan' : (current === steps.length ? 'Tinjau pengajuan' : 'Lanjut');
        submit.classList.toggle('hidden', !reviewing);
    }

    function focusHeading() {
        (reviewing ? review : steps[current - 1]).querySelector('h2').focus();
    }

    function validateAll() {
        const invalidIndex = steps.findIndex((step) => invalidField(step));
        if (invalidIndex !== -1) {
            current = invalidIndex + 1;
            reviewing = false;
            renderStep();
            invalidField(steps[invalidIndex]).reportValidity();
            return false;
        }
        if (levels.some((level) => level.select.disabled || !level.select.value)) {
            current = steps.length;
            reviewing = false;
            renderStep();
            message('Lengkapi pilihan wilayah toko. Jika daftar belum tersedia, gunakan tombol Coba lagi.', true);
            focusHeading();
            return false;
        }
        return true;
    }

    function showReview() {
        if (!validateAll()) return;
        review.querySelectorAll('[data-review-field]').forEach((item) => {
            item.textContent = form.elements.namedItem(item.dataset.reviewField)?.value.trim() || 'Belum diisi';
        });
        reviewing = true;
        editing = false;
        renderStep();
        focusHeading();
    }

    function message(text, error = false) {
        status.textContent = text;
        status.dataset.error = String(error);
    }

    function clearBelow(index) {
        levels.slice(index + 1).forEach((level) => {
            level.controller?.abort();
            level.controller = null;
            level.select.replaceChildren(new Option('Pilih wilayah sebelumnya', ''));
            level.select.disabled = true;
            level.select.removeAttribute('aria-busy');
            level.name.value = '';
        });
        postalCode.value = '';
        postalCode.readOnly = true;
        failedLevel = null;
        retry.hidden = true;
    }

    function updateSelection(index) {
        const level = levels[index];
        const selected = level.select.selectedOptions[0];
        level.name.value = level.select.value ? selected.textContent : '';
        if (index === levels.length - 1) {
            const code = selected?.dataset.postalCode ?? '';
            postalCode.value = code;
            postalCode.readOnly = !level.select.value || Boolean(code);
            message(level.select.value && !code ? 'Kode pos belum tersedia. Silakan isi kode pos alamat toko.' : '');
        }
    }

    async function loadLevel(index, restore = false) {
        const level = levels[index];
        level.controller?.abort();
        const controller = new AbortController();
        level.controller = controller;
        level.select.disabled = true;
        level.select.setAttribute('aria-busy', 'true');
        level.select.replaceChildren(new Option(`Memuat ${level.label.toLowerCase()}…`, ''));
        message(`Memuat ${level.label.toLowerCase()}…`);
        retry.hidden = true;
        failedLevel = null;
        try {
            const url = new URL(`${form.dataset.locationsUrl}/${level.path}`, window.location.origin);
            if (index > 0) {
                const parent = levels[index - 1];
                url.searchParams.set(`${parent.key}_id`, parent.select.value);
            }
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('Lokasi tidak tersedia.');
            const body = await response.json();
            if (!Array.isArray(body?.data?.locations)) throw new Error('Respons lokasi tidak valid.');
            if (level.controller !== controller || controller.signal.aborted) return;
            const options = body.data.locations.map((row) => ({
                id: String(row[`${level.key}_id`] ?? row.id ?? ''),
                name: String(row[`${level.key}_name`] ?? row.name ?? ''),
                postalCode: String(row.postal_code ?? ''),
            })).filter((row) => row.id && row.name);
            if (!options.length) throw new Error('Daftar wilayah kosong.');
            level.select.replaceChildren(new Option(`Pilih ${level.label.toLowerCase()}`, ''));
            for (const row of options) {
                const option = new Option(row.name, row.id);
                option.dataset.postalCode = row.postalCode;
                level.select.add(option);
            }
            level.select.disabled = false;
            message('');
            if (restore && level.select.dataset.selected) {
                level.select.value = level.select.dataset.selected;
                if (level.select.value) {
                    updateSelection(index);
                    if (index === levels.length - 1 && !postalCode.readOnly) postalCode.value = initialPostalCode;
                    if (index < levels.length - 1) await loadLevel(index + 1, true);
                } else {
                    level.name.value = '';
                    message('Pilihan wilayah sebelumnya tidak tersedia. Silakan pilih ulang.', true);
                }
            }
        } catch (error) {
            if (controller.signal.aborted || level.controller !== controller) return;
            level.select.replaceChildren(new Option(`${level.label} belum tersedia`, ''));
            level.name.value = '';
            failedLevel = index;
            retry.hidden = false;
            message(`Gagal memuat ${level.label.toLowerCase()}. Silakan coba lagi.`, true);
        } finally {
            if (level.controller === controller) level.select.removeAttribute('aria-busy');
        }
    }

    levels.forEach((level, index) => {
        level.select.addEventListener('change', () => {
            clearBelow(index);
            updateSelection(index);
            if (level.select.value && index < levels.length - 1) loadLevel(index + 1);
            else if (index < levels.length - 1) message('');
        });
    });
    retry.addEventListener('click', () => {
        if (failedLevel !== null) loadLevel(failedLevel, true);
    });

    function invalidField(step) {
        return [...step.querySelectorAll('input, select, textarea')].find((field) => !field.checkValidity());
    }

    function advance() {
        const invalid = invalidField(steps[current - 1]);
        if (invalid) return invalid.reportValidity();
        if (editing || current === steps.length) return showReview();
        current = Math.min(current + 1, steps.length);
        furthest = Math.max(furthest, current);
        renderStep();
        focusHeading();
    }

    next.addEventListener('click', advance);
    previous.addEventListener('click', () => {
        if (reviewing) {
            reviewing = false;
            current = steps.length;
        } else if (editing) return showReview();
        else current = Math.max(1, current - 1);
        renderStep();
        focusHeading();
    });
    document.querySelectorAll('[data-jump-step], [data-edit-step]').forEach((button) => {
        button.addEventListener('click', () => {
            const step = Number(button.dataset.jumpStep || button.dataset.editStep);
            if (step > furthest) return;
            editing = reviewing || editing;
            reviewing = false;
            current = step;
            renderStep();
            focusHeading();
        });
    });
    form.querySelector('#toggle-password').addEventListener('click', (event) => {
        const password = form.querySelector('#password');
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        event.currentTarget.textContent = visible ? 'Sembunyikan' : 'Tampilkan';
        event.currentTarget.setAttribute('aria-pressed', String(visible));
    });
    form.addEventListener('submit', (event) => {
        if (form.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }
        if (!reviewing) {
            event.preventDefault();
            advance();
            return;
        }
        if (!validateAll()) {
            event.preventDefault();
            return;
        }
        submit.disabled = true;
        submit.textContent = 'Mengirim pengajuan…';
        form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => {
        submit.disabled = false;
        submit.textContent = 'Kirim pengajuan toko';
        form.removeAttribute('aria-busy');
    });

    renderStep();
    loadLevel(0, true);
}
