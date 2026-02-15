(function () {

    if (typeof ASPO_DATA === 'undefined' || !ASPO_DATA.usdRate) {
        return;
    }

    function formatWithNbsp(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '&nbsp;');
    }

    function processPrices() {

        document.querySelectorAll('.hp-listing__attribute--price').forEach(priceEl => {

            if (priceEl.dataset.usdDone) return;

            const strong = priceEl.querySelector('strong');
            if (!strong) return;

            const text = strong.textContent;

            if (!text.includes('грн')) return;

            const cleaned = text
                .replace(/\u00A0/g, '')
                .replace(/[^\d]/g, '');

            if (!cleaned) return;

            const uah = parseInt(cleaned, 10);
            const usd = Math.round((uah / ASPO_DATA.usdRate) / 10) * 10;
            const usdFormatted = formatWithNbsp(usd);

            strong.insertAdjacentHTML(
                'afterend',
                ` <br>(&asymp;&nbsp;${usdFormatted}&nbsp;$)`
            );

            priceEl.dataset.usdDone = 'true';
        });
    }

    // First run
    processPrices();

    // MAIN — run after every HivePress render
    document.addEventListener('hivepress/v1/render', processPrices);

})();
