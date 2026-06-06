const variantIdInput = document.getElementById('variantIdInput');
const mainImg = document.getElementById('mainImg');
const selectedSize = document.getElementById('selectedSize');
const selectedColor = document.getElementById('selectedColor');
const selectedPrice = document.getElementById('selectedPrice');
const selectedSubtotal = document.getElementById('selectedSubtotal');
const selectedStock = document.getElementById('selectedStock');
const headlinePrice = document.getElementById('headlinePrice');
const priceHint = document.getElementById('priceHint');
const priceLabel = document.getElementById('priceLabel');
const priceOriginal = document.getElementById('priceOriginal');
const priceMember = document.getElementById('priceMember');
const priceSpecial = document.getElementById('priceSpecial');
const priceOriginalRow = document.getElementById('priceOriginalRow');
const priceMemberRow = document.getElementById('priceMemberRow');
const priceSpecialRow = document.getElementById('priceSpecialRow');
const thumbList = document.getElementById('thumbList');
const quantityInput = document.getElementById('quantityInput');
const quantityInputMobile = document.getElementById('quantityInputMobile');
const colorButtons = document.querySelectorAll('[data-color-option]');
const sizeButtons = document.querySelectorAll('[data-size-option]');
const favoriteBtn = document.getElementById('favoriteBtn');
const toast = document.getElementById('toast');
const swatches = document.querySelectorAll('.color-swatch');

// DOM 元素參考
const selectedSizeElement = document.getElementById('selectedSize');
const selectedColorElement = document.getElementById('selectedColor');

function formatPrice(value) {
    const number = Number(value);
    if (Number.isNaN(number)) {
        return '尚未設定';
    }
    return 'NT$ ' + number.toLocaleString('zh-TW');
}

function findVariant(variantId) {
    const normalizedId = String(variantId || '');
    return variantData.find(item => String(item.variant_id) === normalizedId) || null;
}

function getVariantUnitPrice(variant) {
    if (!variant) {
        return null;
    }
    const breakdown = getPriceBreakdown(variant);
    return breakdown ? breakdown.headline : null;
}

function getPriceBreakdown(variant) {
    if (!variant) {
        return null;
    }

    const original = Number(variant.original_price);
    const specialValue = (variant.special_price !== null && variant.special_price !== '') ? Number(variant.special_price) : null;
    const memberValue = (variant.member_price !== null && variant.member_price !== '') ? Number(variant.member_price) : null;
    const special = (specialValue !== null && !Number.isNaN(specialValue) && specialValue >= 0) ? specialValue : null;
    const member = (memberValue !== null && !Number.isNaN(memberValue) && memberValue > 0) ? memberValue : null;
    let headline = original;
    let label = '原價';

    if (isMemberUser) {
        const candidates = [original];
        if (special !== null) {
            candidates.push(special);
        }
        if (member !== null) {
            candidates.push(member);
        }
        headline = Math.min(...candidates);
        if (member !== null && headline === member) {
            label = '會員價';
        } else if (special !== null && headline === special) {
            label = '特價';
        }
    } else if (special !== null && special < original) {
        headline = special;
        label = '特價';
    }

    return { original, special, member, headline, label };
}

function getVariantMemberPrice(variant) {
    if (!variant || variant.member_price === null || variant.member_price === '' || Number(variant.member_price) <= 0) {
        return null;
    }
    return Number(variant.member_price);
}

function updateSubtotal() {
    if (!selectedSubtotal) {
        return;
    }
    const variantId = variantIdInput ? variantIdInput.value : '';
    const variant = findVariant(variantId);
    const breakdown = getPriceBreakdown(variant);
    const unitPrice = breakdown ? breakdown.headline : null;
    const quantity = quantityInput ? Math.max(1, parseInt(quantityInput.value || '1', 10) || 1) : 1;

    if (quantityInputMobile && quantityInputMobile.value !== String(quantity)) {
        quantityInputMobile.value = String(quantity);
    }

    if (variant && unitPrice !== null && !Number.isNaN(unitPrice)) {
        selectedSubtotal.textContent = formatPrice(unitPrice * quantity);
    } else {
        selectedSubtotal.textContent = '尚未設定';
    }
}

function updatePriceUI(variant) {
    const breakdown = getPriceBreakdown(variant);
    if (!breakdown) {
        return;
    }

    if (headlinePrice) headlinePrice.textContent = formatPrice(breakdown.headline);
    if (priceLabel) priceLabel.textContent = breakdown.label;
    if (priceOriginal) priceOriginal.textContent = formatPrice(breakdown.original);
    if (priceMember) priceMember.textContent = breakdown.member !== null ? formatPrice(breakdown.member) : '--';
    if (priceSpecial) priceSpecial.textContent = breakdown.special !== null ? formatPrice(breakdown.special) : '--';

    if (priceOriginalRow) priceOriginalRow.classList.toggle('is-hidden', breakdown.label === '原價');
    if (priceMemberRow) priceMemberRow.classList.toggle('is-hidden', breakdown.label === '會員價' || breakdown.member === null);
    if (priceSpecialRow) priceSpecialRow.classList.toggle('is-hidden', breakdown.label === '特價' || breakdown.special === null);

    if (priceHint) {
        if (isMemberUser) {
            if (breakdown.member !== null && breakdown.label === '會員價') {
                priceHint.textContent = '您已享有專屬會員最優惠。';
            } else if (breakdown.member !== null) {
                priceHint.textContent = '會員價仍可使用：' + formatPrice(breakdown.member);
            } else {
                priceHint.textContent = '已顯示目前可用最優惠價格。';
            }
        } else {
            if (breakdown.special !== null && breakdown.special < breakdown.original) {
                priceHint.textContent = '活動特惠價：' + formatPrice(breakdown.special);
            } else if (breakdown.member !== null) {
                priceHint.textContent = '加入會員即可使用會員價：' + formatPrice(breakdown.member);
            } else {
                priceHint.textContent = '加入會員即可查看會員價。';
            }
        }
    }
}

function setChipSelected(buttons, value) {
    buttons.forEach((btn) => {
        const match = btn.dataset.colorOption === value || btn.dataset.sizeOption === value;
        btn.classList.toggle('is-selected', match);
    });
}

function refreshSizeButtons(currentColor) {
    sizeButtons.forEach((btn) => {
        const size = btn.dataset.sizeOption;
        const variant = variantData.find((item) => item.color === currentColor && item.size_label === size);
        const inStock = variant && Number(variant.stock_available) > 0;
        btn.disabled = !variant || !inStock;
        btn.classList.toggle('is-disabled', !variant || !inStock);
    });
}

function pickVariant(currentColor, currentSize) {
    let variant = null;
    if (currentColor && currentSize) {
        variant = variantData.find((item) => item.color === currentColor && item.size_label === currentSize) || null;
    }
    if (!variant && currentColor) {
        variant = variantData.find((item) => item.color === currentColor) || null;
    }
    if (!variant && currentSize) {
        variant = variantData.find((item) => item.size_label === currentSize) || null;
    }
    if (!variant) {
        variant = variantData[0] || null;
    }
    return variant;
}

function applyVariant(variantId, imageUrl) {
    const variant = findVariant(variantId);
    if (!variant) return;

    // 全域變數同步（這些變數已由 PHP 在主檔案中初始化）
    globalSelectedColor = variant.color;
    globalSelectedSize = variant.size_label;
    
    if (colorButtons.length > 0) setChipSelected(colorButtons, globalSelectedColor);
    if (sizeButtons.length > 0) setChipSelected(sizeButtons, globalSelectedSize);
    
    refreshSizeButtons(globalSelectedColor);

    if (variantIdInput) variantIdInput.value = String(variant.variant_id);
    if (selectedSizeElement) {
        const sizeLabel = variant.size_label || '未設定';
        const sizeDisplay = variant.size_inches ? ` (${variant.size_inches})` : '';
        selectedSizeElement.textContent = `${sizeLabel}${sizeDisplay}`;
    }
    if (selectedColorElement) selectedColorElement.textContent = variant.color || '未設定';
    if (selectedPrice) {
        const breakdown = getPriceBreakdown(variant);
        const priceValue = breakdown ? breakdown.headline : null;
        selectedPrice.textContent = priceValue !== null && !Number.isNaN(priceValue) ? formatPrice(priceValue) : '尚未設定';
    }
    
    updatePriceUI(variant);
    
    if (selectedStock) selectedStock.textContent = String(variant.stock_available ?? 0);
    
    if (mainImg) {
        const nextImage = imageUrl || variant.image_url;
        if (nextImage) mainImg.src = nextImage;
    }

    updateSubtotal();

    if (thumbList) {
        const thumbs = thumbList.querySelectorAll('img[data-image-url]');
        thumbs.forEach((thumb) => {
            const isActive = thumb.dataset.imageUrl === (imageUrl || variant.image_url);
            thumb.style.outline = isActive ? '2px solid #db6b6b' : 'none';
            thumb.style.outlineOffset = isActive ? '2px' : '0';
        });
    }
}

// 綁定事件監聽器
if (colorButtons.length > 0) {
    colorButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            globalSelectedColor = btn.dataset.colorOption;
            setChipSelected(colorButtons, globalSelectedColor);
            refreshSizeButtons(globalSelectedColor);

            const enabledSize = Array.from(sizeButtons).find((sizeBtn) => !sizeBtn.disabled);
            if (!globalSelectedSize || !Array.from(sizeButtons).some((s) => s.dataset.sizeOption === globalSelectedSize && !s.disabled)) {
                globalSelectedSize = enabledSize ? enabledSize.dataset.sizeOption : '';
            }
            if (globalSelectedSize) setChipSelected(sizeButtons, globalSelectedSize);

            const variant = pickVariant(globalSelectedColor, globalSelectedSize);
            if (variant) {
                applyVariant(variant.variant_id, btn.dataset.imageUrl || variant.image_url);
            }
        });
    });
    // 初始化尺寸按鈕狀態
    refreshSizeButtons(globalSelectedColor);
}

if (sizeButtons.length > 0) {
    sizeButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            globalSelectedSize = btn.dataset.sizeOption;
            setChipSelected(sizeButtons, globalSelectedSize);
            const variant = pickVariant(globalSelectedColor, globalSelectedSize);
            if (variant) {
                globalSelectedColor = variant.color;
                setChipSelected(colorButtons, globalSelectedColor);
                refreshSizeButtons(globalSelectedColor);
                applyVariant(variant.variant_id, variant.image_url);
            }
        });
    });
}

if (quantityInput) {
    quantityInput.addEventListener('input', updateSubtotal);
    quantityInput.addEventListener('change', updateSubtotal);
}

if (quantityInputMobile) {
    quantityInputMobile.addEventListener('input', () => {
        if (quantityInput) {
            quantityInput.value = quantityInputMobile.value;
        }
        updateSubtotal();
    });
}

if (thumbList) {
    thumbList.addEventListener('click', (event) => {
        const thumb = event.target.closest('img[data-image-url]');
        if (!thumb) return;

        if (mainImg) mainImg.src = thumb.dataset.imageUrl;

        const matchedVariant = variantData.find((item) => item.image_url && item.image_url === thumb.dataset.imageUrl);

        if (matchedVariant) {
            applyVariant(matchedVariant.variant_id, thumb.dataset.imageUrl);
        } else if (thumb.dataset.color) {
            const colorMatched = variantData.find((item) => item.color === thumb.dataset.color);
            if (colorMatched) {
                applyVariant(colorMatched.variant_id, thumb.dataset.imageUrl);
            }
        }
    });
}

// 💡 真實發送 AJAX 儲存到資料庫的版本
if (favoriteBtn && toast) {
    favoriteBtn.addEventListener('click', (e) => {
        e.preventDefault(); // 防止按鈕觸發表單送出

        if (typeof isLoggedIn !== 'undefined' && !isLoggedIn) {
            alert('請先登入會員才能加入收藏！');
            window.location.href = 'login.php';
            return;
        }

        favoriteBtn.classList.add('is-animating');

        // 準備傳送給後端的資料
        const formData = new URLSearchParams();
        formData.append('action', 'toggle_favorite');
        if (typeof csrfToken !== 'undefined') {
            formData.append('csrf_token', csrfToken);
        }
        if (typeof currentProductId !== 'undefined') {
            formData.append('product_id', currentProductId);
        }

        // 發送請求到 PHP
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const isAdded = data.status === 'added';
                // 根據後端回傳的狀態來切換 UI
                favoriteBtn.classList.toggle('is-active', isAdded);
                favoriteBtn.setAttribute('aria-pressed', isAdded ? 'true' : 'false');
                
                toast.textContent = isAdded ? '已加入收藏' : '已移除收藏';
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2000);
            } else {
                alert('操作失敗：' + (data.error || '未知錯誤'));
            }
        })
        .catch(err => console.error('收藏發生錯誤:', err))
        .finally(() => {
            setTimeout(() => favoriteBtn.classList.remove('is-animating'), 150);
        });
    });
}

const colorMap = {
    '黑': '#111827', '黑色': '#111827',
    '白': '#f9fafb', '白色': '#f9fafb',
    '灰': '#9ca3af', '灰色': '#9ca3af',
    '紅': '#ef4444', '紅色': '#ef4444',
    '藍': '#3b82f6', '藍色': '#3b82f6',
    '綠': '#10b981', '綠色': '#10b981',
    '卡其': '#d6b88b',
    '棕': '#8b5e3c', '棕色': '#8b5e3c',
    '銀': '#d1d5db', '銀色': '#d1d5db'
};

swatches.forEach((swatch) => {
    const key = swatch.dataset.color || '';
    const color = colorMap[key] || '#d1d5db';
    swatch.style.background = color;
});
