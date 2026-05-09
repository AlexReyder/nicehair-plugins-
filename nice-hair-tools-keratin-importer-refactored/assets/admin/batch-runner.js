(function () {
    const root = document.getElementById('nh-tki-runner');

    if (!root) {
        return;
    }

    const progressText = document.getElementById('nh-tki-progress-text');
    const progressBar = document.getElementById('nh-tki-progress-bar');
    const reportRoot = document.getElementById('nh-tki-report');
    const runId = root.dataset.runId;
    const nonce = root.dataset.nonce;
    const ajaxUrl = root.dataset.ajaxUrl;
    const action = root.dataset.action || 'nh_tki_process_run';
    let stopped = false;

    const tick = function () {
        if (stopped) {
            return;
        }

        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', nonce);
        formData.append('run_id', runId);
        formData.append('batch_size', '1');

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Batch import request failed.');
                }

                const data = payload.data || {};

                if (progressText && data.progress_label) {
                    progressText.textContent = data.progress_label;
                }

                if (progressBar) {
                    progressBar.value = Number(data.processed || 0);
                }

                if (data.done) {
                    stopped = true;

                    if (progressText) {
                        progressText.textContent = 'Импорт завершён.';
                    }

                    if (reportRoot && data.report_html) {
                        reportRoot.innerHTML = data.report_html;
                    }

                    return;
                }

                window.setTimeout(tick, 150);
            })
            .catch(function (error) {
                stopped = true;

                if (progressText) {
                    progressText.textContent = 'Ошибка batch-импорта: ' + error.message;
                }
            });
    };

    tick();
}());
