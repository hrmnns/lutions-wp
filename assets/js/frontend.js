(function () {
    function normalizePublicSearchResults() {
        var results = document.querySelector('.lutions-wp-search-results');
        if (!results) {
            return;
        }

        var emptyState = document.querySelector(
            '.wp-block-query-no-results, .site-main .no-results, main .no-results, .site-main .not-found, main .not-found'
        );
        if (!emptyState || !emptyState.parentNode) {
            return;
        }

        emptyState.classList.add('lutions-wp-core-empty-hidden');
        emptyState.parentNode.insertBefore(results, emptyState);
    }

    function isAllowedYouTubeEmbedUrl(src) {
        try {
            var url = new URL(src, window.location.href);
            return url.protocol === 'https:'
                && url.hostname === 'www.youtube-nocookie.com'
                && /^\/embed\/[A-Za-z0-9_-]{11}$/.test(url.pathname);
        } catch (error) {
            return false;
        }
    }

    function loadYouTubeEmbed(container) {
        var src = container.getAttribute('data-embed-src');
        var title = container.getAttribute('data-embed-title') || 'YouTube video';
        if (!src || !isAllowedYouTubeEmbedUrl(src) || container.querySelector('iframe')) {
            return;
        }

        var iframe = document.createElement('iframe');
        iframe.setAttribute('src', src);
        iframe.setAttribute('title', title);
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');

        container.classList.remove('lutions-wp-youtube-embed-placeholder');
        container.textContent = '';
        container.appendChild(iframe);
    }

    function submissionMessage(container, message, isError) {
        container.textContent = message;
        container.classList.toggle('is-error', Boolean(isError));
        container.classList.toggle('is-success', !isError && message !== '');
        if (message) {
            container.focus();
        }
    }

    function initPublicSubmission(form) {
        var fieldset = form.querySelector('fieldset');
        var status = form.parentNode.querySelector('.lutions-wp-submission-status');
        var challenge = form.querySelector('.lutions-wp-submission-challenge');
        var challengeLabel = challenge.querySelector('label');
        var challengeHelp = challenge.querySelector('span');
        var challengeInput = challenge.querySelector('input');
        var apiBaseUrl = form.getAttribute('data-api-base-url');
        var descriptionMinLength = Number(form.getAttribute('data-description-min-length') || 20);
        var verification = null;
        var verificationToken = '';
        var submitButton = form.querySelector('button[type="submit"]');

        if (!fieldset || !status || !challenge || !challengeLabel || !challengeHelp || !challengeInput || !apiBaseUrl || !submitButton) {
            return;
        }

        function unavailable(message) {
            fieldset.disabled = true;
            form.setAttribute('aria-busy', 'false');
            submissionMessage(status, message, true);
        }

        function loadVerificationScript(id, source, available) {
            if (available()) return Promise.resolve();
            return new Promise(function (resolve, reject) {
                var existing = document.getElementById(id);
                var script = existing || document.createElement('script');
                var remainingAttempts = 20;
                function complete() {
                    if (available()) {
                        resolve();
                        return;
                    }
                    if (remainingAttempts > 0) {
                        remainingAttempts -= 1;
                        window.setTimeout(complete, 100);
                        return;
                    }
                    reject(new Error('unavailable'));
                }
                script.addEventListener('load', complete, { once: true });
                script.addEventListener('error', function () { reject(new Error('failed')); }, { once: true });
                if (!existing) {
                    script.id = id;
                    script.src = source;
                    script.async = true;
                    script.defer = true;
                    document.head.appendChild(script);
                } else {
                    complete();
                }
            });
        }

        function renderProviderWidget() {
            var mount = document.createElement('div');
            challenge.appendChild(mount);
            if (verification.provider === 'turnstile') {
                return loadVerificationScript('lutions-wp-turnstile-api', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', function () { return Boolean(window.turnstile); })
                    .then(function () { window.turnstile.render(mount, { sitekey: verification.siteKey, action: 'public_submission', callback: function (token) { verificationToken = token; }, 'expired-callback': function () { verificationToken = ''; }, 'error-callback': function () { verificationToken = ''; unavailable(form.getAttribute('data-unavailable-message') || ''); } }); });
            }
            return loadVerificationScript('lutions-wp-recaptcha-v2-api', 'https://www.google.com/recaptcha/api.js?render=explicit', function () { return Boolean(window.grecaptcha && typeof window.grecaptcha.render === 'function'); })
                .then(function () { window.grecaptcha.render(mount, { sitekey: verification.siteKey, callback: function (token) { verificationToken = token; }, 'expired-callback': function () { verificationToken = ''; }, 'error-callback': function () { verificationToken = ''; unavailable(form.getAttribute('data-unavailable-message') || ''); } }); });
        }

        submissionMessage(status, form.getAttribute('data-loading-message') || '', false);
        fetch(apiBaseUrl + '/public/submissions/config', { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json().then(function (payload) { return { response: response, payload: payload }; }); })
            .then(function (result) {
                var config = result.payload && result.payload.data;
                if (!result.response.ok || !config || !config.enabled || !config.verification) {
                    unavailable(form.getAttribute('data-unavailable-message') || '');
                    return;
                }
                verification = config.verification;
                if (verification.required && verification.provider !== 'local_challenge' && verification.provider !== 'turnstile' && verification.provider !== 'recaptcha_v2') {
                    unavailable(form.getAttribute('data-unsupported-message') || '');
                    return;
                }
                if (verification.provider === 'local_challenge' && verification.challenge) {
                    challenge.hidden = false;
                    challengeLabel.textContent = (form.getAttribute('data-verification-label') || '') + ': ' + verification.challenge.question;
                    challengeHelp.textContent = form.getAttribute('data-verification-help') || '';
                    challengeHelp.hidden = false;
                    challengeInput.hidden = false;
                    challengeInput.disabled = false;
                    challengeInput.required = true;
                }
                if ((verification.provider === 'turnstile' || verification.provider === 'recaptcha_v2') && verification.siteKey) {
                    challenge.hidden = false;
                    challengeLabel.textContent = form.getAttribute('data-verification-label') || '';
                    challengeHelp.hidden = true;
                    challengeInput.hidden = true;
                    challengeInput.disabled = true;
                    challengeInput.required = false;
                    renderProviderWidget().catch(function (error) { console.error('Lutions CAPTCHA provider could not be initialized.', error); unavailable(form.getAttribute('data-unavailable-message') || ''); });
                }
                fieldset.disabled = false;
                form.setAttribute('aria-busy', 'false');
                submissionMessage(status, '', false);
            })
            .catch(function () { unavailable(form.getAttribute('data-unavailable-message') || ''); });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var fields = new FormData(form);
            var name = String(fields.get('name') || '').trim();
            var contact = String(fields.get('contact') || '').trim();
            var subject = String(fields.get('subject') || '').trim();
            var description = String(fields.get('description') || '').trim();
            var answer = String(fields.get('verificationAnswer') || '').trim();
            if (verification && verification.provider === 'local_challenge' && !answer) {
                submissionMessage(status, form.getAttribute('data-verification-help') || '', true);
                challengeInput.focus();
                return;
            }
            if (verification && (verification.provider === 'turnstile' || verification.provider === 'recaptcha_v2') && !verificationToken) {
                submissionMessage(status, form.getAttribute('data-verification-help') || '', true);
                return;
            }
            if (description.length < descriptionMinLength) {
                submissionMessage(status, form.getAttribute('data-description-too-short-message') || '', true);
                form.querySelector('[name="description"]').focus();
                return;
            }
            if ((!name && !contact) || subject.length < 5) {
                submissionMessage(status, form.getAttribute('data-required-message') || '', true);
                return;
            }
            fieldset.disabled = true;
            submitButton.textContent = form.getAttribute('data-sending-message') || submitButton.textContent;
            fetch(apiBaseUrl + '/public/submissions', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: fields.get('type'), name: name, contact: contact, updateEmail: String(fields.get('updateEmail') || '').trim(),
                    subject: subject, description: description, verificationToken: verificationToken || (verification && verification.challenge ? verification.challenge.token : ''),
                    verificationAnswer: answer, companyWebsite: String(fields.get('companyWebsite') || '')
                })
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) { return { response: response, payload: payload }; });
            }).then(function (result) {
                if (!result.response.ok || !result.payload.data || !result.payload.data.accepted) {
                    throw new Error('rejected');
                }
                form.hidden = true;
                submissionMessage(status, (form.getAttribute('data-success-message') || '') + ' ' + (result.payload.data.reference || ''), false);
            }).catch(function () {
                fieldset.disabled = false;
                submitButton.textContent = 'Send report';
                submissionMessage(status, form.getAttribute('data-network-message') || '', true);
            });
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var button = target.closest('[data-lutions-wp-youtube-load]');
        if (!button) {
            return;
        }

        var container = button.closest('[data-lutions-wp-youtube-embed]');
        if (!container) {
            return;
        }

        event.preventDefault();
        loadYouTubeEmbed(container);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            normalizePublicSearchResults();
            document.querySelectorAll('[data-lutions-wp-submission]').forEach(initPublicSubmission);
        });
    } else {
        normalizePublicSearchResults();
        document.querySelectorAll('[data-lutions-wp-submission]').forEach(initPublicSubmission);
    }
}());
