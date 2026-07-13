/**
 * Vanilla JS for the custom cart page (/customcart/cart) -- no build step,
 * no framework, plain fetch(). Sends form-urlencoded bodies (not JSON) so
 * Magento's RequestInterface::getParam() picks values up natively.
 *
 * X-Requested-With: XMLHttpRequest on every POST satisfies Magento's
 * default CsrfValidator XHR-detection path (see the PHP controllers'
 * docblocks) without needing CsrfAwareActionInterface.
 *
 * Endpoint paths are absolute ("/customcart/cart/...") rather than
 * relative -- this page's own URL already has extra path segments, so a
 * relative fetch() URL would resolve incorrectly. Assumes a root-level
 * Magento install (true for both the local Docker and GCP VM deploys this
 * module targets); a subdirectory install would need these built from
 * window.BASE_URL instead.
 */
(function () {
    'use strict';

    function postForm(url, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (key) {
            body.append(key, params[key]);
        });

        return fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        });
    }

    function showGlobalError(message) {
        var banner = document.querySelector('[data-role="global-error"]');
        if (banner) {
            banner.textContent = message;
            banner.hidden = false;
        }
    }

    function clearGlobalError() {
        var banner = document.querySelector('[data-role="global-error"]');
        if (banner) {
            banner.hidden = true;
            banner.textContent = '';
        }
    }

    function renderTotals(totals) {
        var subtotalEl = document.querySelector('[data-role="subtotal"]');
        if (subtotalEl) {
            subtotalEl.textContent = '$' + Number(totals.subtotal).toFixed(2);
        }

        var grandTotalEl = document.querySelector('[data-role="grand-total"]');
        if (grandTotalEl) {
            grandTotalEl.textContent = '$' + Number(totals.grand_total).toFixed(2);
        }

        var taxRates = totals.tax_rates || [];
        var taxRowsEl = document.querySelector('[data-role="tax-rows"]');
        if (taxRowsEl) {
            // textContent clear, then rebuild via createElement -- never
            // innerHTML, even though rate.title here is server-validated
            // config data, not user input; consistent practice throughout.
            taxRowsEl.textContent = '';

            taxRates.forEach(function (rate) {
                var row = document.createElement('div');
                row.className = 'customcart-totals-row customcart-tax-row';

                var label = document.createElement('span');
                label.className = 'customcart-totals-label';
                label.textContent = rate.title + ' (' + rate.percent + '%)';

                var value = document.createElement('span');
                value.className = 'customcart-totals-value';
                value.textContent = '$' + Number(rate.amount).toFixed(2);

                row.appendChild(label);
                row.appendChild(value);
                taxRowsEl.appendChild(row);
            });
        }

        var placeholderEl = document.querySelector('[data-role="tax-placeholder"]');
        if (placeholderEl) {
            placeholderEl.hidden = taxRates.length > 0;
        }

        var discountAmount = Number(totals.discount_amount || 0);
        var discountRowEl = document.querySelector('[data-role="discount-row"]');
        var discountAmountEl = document.querySelector('[data-role="discount-amount"]');
        if (discountRowEl) {
            discountRowEl.hidden = discountAmount === 0;
        }
        if (discountAmountEl) {
            discountAmountEl.textContent = '-$' + Math.abs(discountAmount).toFixed(2);
        }
    }

    function renderRowTotal(itemId, items) {
        var item = (items || []).filter(function (candidate) {
            return String(candidate.item_id) === String(itemId);
        })[0];

        if (!item) {
            return;
        }

        var rowTotalEl = document.querySelector('[data-role="row-total"][data-item-id="' + itemId + '"]');
        if (rowTotalEl) {
            rowTotalEl.textContent = '$' + Number(item.row_total).toFixed(2);
        }
    }

    /**
     * Luma's header mini-cart reads item list/count/subtotal from the
     * "cart" customer-data section, cached client-side in localStorage --
     * NOT re-fetched automatically just because this page's own AJAX
     * mutated the quote server-side. Core cart AJAX flows (e.g.
     * Magento_Checkout's sidebar qty/remove handlers) explicitly
     * invalidate + reload this section after every mutation; this page's
     * independent fetch()-based flow needs to do the same, or the
     * mini-cart silently shows stale items/count/subtotal until the next
     * full page load. Called after remove, qty update, and tax estimate
     * (the last one changes the subtotal/grand-total display Luma's
     * minicart also renders) -- not after comment save, since a comment
     * has no effect on anything the minicart shows.
     */
    function invalidateMiniCart() {
        require(['Magento_Customer/js/customer-data'], function (customerData) {
            customerData.invalidate(['cart']);
            customerData.reload(['cart'], true);
        });
    }

    function setCheckoutButtonDisabled(disabled) {
        var checkoutBtn = document.querySelector('[data-role="checkout-btn"]');
        if (!checkoutBtn) {
            return;
        }

        if (disabled) {
            checkoutBtn.setAttribute('aria-disabled', 'true');
            checkoutBtn.setAttribute('tabindex', '-1');
        } else {
            checkoutBtn.removeAttribute('aria-disabled');
            checkoutBtn.removeAttribute('tabindex');
        }
    }

    function removeRow(itemId) {
        var row = document.querySelector('.customcart-item-row[data-item-id="' + itemId + '"]');
        if (row && row.parentNode) {
            row.parentNode.removeChild(row);
        }

        var itemsBody = document.querySelector('[data-role="items-body"]');
        if (itemsBody && itemsBody.children.length === 0) {
            var table = document.querySelector('[data-role="items-table"]');
            var emptyMessage = document.querySelector('[data-role="empty-message"]');

            if (table) {
                table.hidden = true;
            }
            if (emptyMessage) {
                emptyMessage.hidden = false;
            }

            setCheckoutButtonDisabled(true);
        }
    }

    function getRegionsData() {
        var el = document.querySelector('[data-role="regions-data"]');
        if (!el) {
            return {};
        }

        try {
            return JSON.parse(el.getAttribute('data-regions') || '{}');
        } catch (e) {
            return {};
        }
    }

    function populateRegionSelect(countryId) {
        var regionSelect = document.querySelector('[data-role="estimate-region"]');
        if (!regionSelect) {
            return;
        }

        var regionsByCountry = getRegionsData();
        var regions = regionsByCountry[countryId];

        // Rebuilt via createElement, never innerHTML -- region names are
        // server-sourced config data, not user input, but this keeps the
        // whole file consistent about never using innerHTML anywhere.
        regionSelect.textContent = '';

        if (!countryId) {
            var selectCountryFirst = document.createElement('option');
            selectCountryFirst.value = '';
            selectCountryFirst.textContent = 'Select a country first';
            regionSelect.appendChild(selectCountryFirst);
            regionSelect.disabled = true;
            return;
        }

        if (!regions) {
            var noRegions = document.createElement('option');
            noRegions.value = '';
            noRegions.textContent = 'No region required';
            regionSelect.appendChild(noRegions);
            regionSelect.disabled = true;
            return;
        }

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select a region';
        regionSelect.appendChild(placeholder);

        Object.keys(regions).forEach(function (regionId) {
            var option = document.createElement('option');
            option.value = regionId;
            option.textContent = regions[regionId].name;
            regionSelect.appendChild(option);
        });

        regionSelect.disabled = false;
    }

    document.addEventListener('change', function (event) {
        var target = event.target;

        if (target.classList && target.classList.contains('customcart-qty-input')) {
            var itemId = target.getAttribute('data-item-id');
            var qty = target.value;

            clearGlobalError();

            postForm('/customcart/cart/update', { item_id: itemId, qty: qty })
                .then(function (result) {
                    if (!result.ok || !result.data.success) {
                        showGlobalError(result.data.message || 'Could not update quantity.');
                        return;
                    }

                    renderRowTotal(itemId, result.data.items);
                    renderTotals(result.data.totals);
                    invalidateMiniCart();
                })
                .catch(function () {
                    showGlobalError('Could not update quantity. Please try again.');
                });

            return;
        }

        if (target.getAttribute && target.getAttribute('data-role') === 'estimate-country') {
            populateRegionSelect(target.value);
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;

        if (target.classList && target.classList.contains('customcart-remove-btn')) {
            var itemId = target.getAttribute('data-item-id');
            clearGlobalError();

            postForm('/customcart/cart/remove', { item_id: itemId })
                .then(function (result) {
                    if (!result.ok || !result.data.success) {
                        showGlobalError(result.data.message || 'Could not remove item.');
                        return;
                    }

                    removeRow(itemId);
                    renderTotals(result.data.totals);
                    invalidateMiniCart();
                })
                .catch(function () {
                    showGlobalError('Could not remove item. Please try again.');
                });

            return;
        }

        if (target.classList && target.classList.contains('customcart-comment-save')) {
            var commentItemId = target.getAttribute('data-item-id');
            var textarea = document.querySelector('.customcart-comment-input[data-item-id="' + commentItemId + '"]');
            var statusEl = document.querySelector('[data-role="comment-status"][data-item-id="' + commentItemId + '"]');

            if (!textarea) {
                return;
            }

            if (statusEl) {
                statusEl.textContent = '';
            }

            postForm('/customcart/cart/comment', { item_id: commentItemId, comment: textarea.value })
                .then(function (result) {
                    if (!result.ok || !result.data.success) {
                        if (statusEl) {
                            statusEl.textContent = result.data.message || 'Could not save comment.';
                        }
                        return;
                    }

                    // Assigning .value on a textarea, like .textContent on
                    // any other element, never parses the string as HTML --
                    // a second, independent layer of XSS defense beyond
                    // the server's own escapeHtml()/parameterized storage.
                    textarea.value = result.data.comment;

                    if (statusEl) {
                        statusEl.textContent = 'Saved.';
                    }
                })
                .catch(function () {
                    if (statusEl) {
                        statusEl.textContent = 'Could not save comment. Please try again.';
                    }
                });

            return;
        }

        if (target.getAttribute && target.getAttribute('data-role') === 'estimate-submit') {
            var countrySelect = document.querySelector('[data-role="estimate-country"]');
            var regionSelect = document.querySelector('[data-role="estimate-region"]');
            var postcodeInput = document.querySelector('[data-role="estimate-postcode"]');
            var estimateStatusEl = document.querySelector('[data-role="estimate-status"]');

            if (!countrySelect) {
                return;
            }

            if (estimateStatusEl) {
                estimateStatusEl.textContent = '';
            }

            postForm('/customcart/cart/estimatetax', {
                country_id: countrySelect.value,
                region_id: regionSelect ? regionSelect.value : '',
                postcode: postcodeInput ? postcodeInput.value : ''
            })
                .then(function (result) {
                    if (!result.ok || !result.data.success) {
                        if (estimateStatusEl) {
                            estimateStatusEl.textContent = result.data.message || 'Could not estimate tax.';
                        }
                        return;
                    }

                    renderTotals(result.data.totals);
                    invalidateMiniCart();

                    if (estimateStatusEl) {
                        estimateStatusEl.textContent = 'Updated.';
                    }
                })
                .catch(function () {
                    if (estimateStatusEl) {
                        estimateStatusEl.textContent = 'Could not estimate tax. Please try again.';
                    }
                });
        }
    });
})();
