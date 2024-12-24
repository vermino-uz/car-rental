let tg = window.Telegram.WebApp;
tg.expand();

// Set theme colors
tg.onEvent('themeChanged', setThemeColors);
setThemeColors();

function setThemeColors() {
    document.documentElement.style.setProperty('--tg-theme-bg-color', tg.themeParams.bg_color || '#fff');
    document.documentElement.style.setProperty('--tg-theme-text-color', tg.themeParams.text_color || '#000');
    document.documentElement.style.setProperty('--tg-theme-hint-color', tg.themeParams.hint_color || '#999');
    document.documentElement.style.setProperty('--tg-theme-link-color', tg.themeParams.link_color || '#2678b6');
    document.documentElement.style.setProperty('--tg-theme-button-color', tg.themeParams.button_color || '#2678b6');
    document.documentElement.style.setProperty('--tg-theme-button-text-color', tg.themeParams.button_text_color || '#fff');
    document.documentElement.style.setProperty('--tg-theme-secondary-bg-color', tg.themeParams.secondary_bg_color || '#f4f4f5');
}

// Setup Main Button
tg.MainButton.text = "Ijarani rasmiylashtirish";
tg.MainButton.color = tg.themeParams.button_color;
tg.MainButton.textColor = tg.themeParams.button_text_color;

const form = document.getElementById('rentalForm');

form.addEventListener('input', () => {
    if(form.checkValidity()) {
        tg.MainButton.show();
    } else {
        tg.MainButton.hide();
    }
});

tg.MainButton.onClick(() => {
    if(form.checkValidity()) {
        form.submit();
    }
});

function updateMileage(carId) {
    const select = document.getElementById('car_id');
    const option = select.options[select.selectedIndex];
    if (option) {
        document.getElementById('start_mileage').value = option.dataset.mileage;
    }
}

function toggleDepositFields() {
    const moneyDeposit = document.getElementById('money_deposit');
    const goodsDeposit = document.getElementById('goods_deposit');
    const depositAmount = document.getElementById('deposit_amount');
    const depositItems = document.getElementById('deposit_items');
    
    if (document.querySelector('input[name="deposit_type"]:checked').value === 'money') {
        moneyDeposit.style.display = 'block';
        goodsDeposit.style.display = 'none';
        depositAmount.required = true;
        depositItems.required = false;
    } else {
        moneyDeposit.style.display = 'none';
        goodsDeposit.style.display = 'block';
        depositAmount.required = false;
        depositItems.required = true;
    }
}

// Format phone number as user types
const phoneInput = document.getElementById('customer_phone');
phoneInput.addEventListener('input', (e) => {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 0 && !value.startsWith('998')) {
        value = '998' + value;
    }
    if (value.length > 12) {
        value = value.slice(0, 12);
    }
    e.target.value = '+' + value;
}); 