wp.domReady(function() {
    const PROCEEDS_INPUT_ID = 'cpw_proceeds_use'; // Set directly as requested

    const originalFetch = window.fetch;

    window.fetch = function(input, init) {
        const url = typeof input === 'string' ? input : input.url;

        ;

        if (url && url.includes('/wc/store/v1/checkout')) {
           

            let requestBodyPromise;

            // FIRST ATTEMPT: Check if 'init.body' exists and is a string (most common for JSON payloads)
            if (init && typeof init.body === 'string') {
                console.log('DEBUG: Found init.body as a string. Using it directly.');
                requestBodyPromise = Promise.resolve(init.body);
            }
            // SECOND ATTEMPT: If 'input' is a Request object, try cloning and reading its text
            else if (input instanceof Request) {
                console.log('DEBUG: init.body not a string. Cloning Request object to read body.');
                requestBodyPromise = input.clone().text();
            }
            // FALLBACK: No recognizable body source, resolve with an empty string
            else {
                console.log('DEBUG: No clear request body found in init or input. Assuming empty body.');
                requestBodyPromise = Promise.resolve('');
            }


            // Now, process the requestBodyPromise
            return requestBodyPromise.then(rawBodyText => {
                //console.log('DEBUG: Raw request body text (from promise):', rawBodyText);
                let requestBody = {};
                try {
                    if (rawBodyText) { // Only try to parse if there's content
                        requestBody = JSON.parse(rawBodyText);
                    }
                } catch (e) {
                    console.warn('Proceeds Checkout Warning: Could not parse original request body as JSON. Assuming empty or malformed body.', e, 'Raw body:', rawBodyText);
                    requestBody = {};
                }

                //console.log('DEBUG: Original request body (parsed, before modification):', requestBody);

                const inputElement = document.getElementById(PROCEEDS_INPUT_ID);
                const amount = inputElement ? inputElement.value : '';
                //console.log('DEBUG: Value from input field (' + PROCEEDS_INPUT_ID + '):', amount);

                if (!requestBody.extensions || typeof requestBody.extensions !== 'object') {
                    requestBody.extensions = {};
                }
                if (!requestBody.extensions.proceeds_discount_extension || typeof requestBody.extensions.proceeds_discount_extension !== 'object') {
                    requestBody.extensions.proceeds_discount_extension = {};
                }
                requestBody.extensions.proceeds_discount_extension.proceeds_amount_applied = amount;

                //console.log('DEBUG: Request body AFTER modification:', requestBody);

                const newHeaders = new Headers(init ? init.headers : {});
                newHeaders.set('Content-Type', 'application/json');

                const newInit = {
                    ...init,
                    method: (init && init.method) ? init.method : 'POST',
                    headers: newHeaders,
                    body: JSON.stringify(requestBody) // Set the modified body
                };

                //console.log('DEBUG: New init object passed to originalFetch:', newInit);

                return originalFetch(url, newInit);
            }).catch(error => {
                //console.error('Proceeds Checkout ERROR during body processing or modification:', error);
                return originalFetch(input, init);
            });
        }

        // For all other requests (not the checkout endpoint), just call the original fetch function
        return originalFetch(input, init);
    };

    const checkoutBlockWrapper = document.querySelector('.wc-block-checkout');
    if (checkoutBlockWrapper) {
        checkoutBlockWrapper.addEventListener('submit', function(event) {
            const inputElement = document.getElementById(PROCEEDS_INPUT_ID);
            if (inputElement) {
                const amount = parseFloat(inputElement.value);
                if (inputElement.value !== '' && (isNaN(amount) || amount < 0)) {
                    alert('Please enter a valid positive number for the proceeds amount.');
                    event.preventDefault();
                    event.stopPropagation();
                    return false;
                }
            }
            return true;
        }, true);
    }

   // console.log('Proceeds checkout Fetch API interceptor loaded and running.');
});

jQuery(function($){
    if (typeof jQuery !== 'undefined') {
        console.log('jQuery is available.');
    } else {
        console.error('jQuery is NOT available. Stopping script.');
        return;
    }

    let isApplyingProceeds = false;

    $(document).on('click', '#apply_proceeds_balance_button', function(e){
        e.preventDefault();

        if (isApplyingProceeds) {
            return;
        }
        isApplyingProceeds = true;

        var $button = $(this);
        var originalButtonText = $button.text();
        var $errorMessageSpan = $('#cpw-proceeds-validation-error');
 // test
        $errorMessageSpan.html('');

        var $proceedsInput = $('#cpw_proceeds_use');
        var inputValue = $proceedsInput.val().trim();

        var proceedsAmount;

        if (inputValue === '') {
            $errorMessageSpan.html('Please enter an amount to apply.');
            $button.prop('disabled', false).text(originalButtonText);
            $('body').css('cursor', 'default');
            isApplyingProceeds = false;
            return;
        }

        if (!/^\d*\.?\d*$/.test(inputValue)) {
            $errorMessageSpan.html('Please enter a valid numeric amount (digits and optional decimal only).');
            $button.prop('disabled', false).text(originalButtonText);
            $('body').css('cursor', 'default');
            isApplyingProceeds = false;
            return;
        }

        proceedsAmount = parseFloat(inputValue);

        if (isNaN(proceedsAmount) || proceedsAmount < 0) {
            $errorMessageSpan.html('Please enter a valid positive amount for proceeds.');
            $button.prop('disabled', false).text(originalButtonText);
            $('body').css('cursor', 'default');
            isApplyingProceeds = false;
            return;
        }

        var maxAllowedBalance = parseFloat(cpw_proceeds_ajax.max_proceeds_balance);

        if (isNaN(maxAllowedBalance) || proceedsAmount > maxAllowedBalance) {
            $errorMessageSpan.html('You cannot apply more than your available balance of ' + maxAllowedBalance.toFixed(2) + '.');
            $button.prop('disabled', false).text(originalButtonText);
            $('body').css('cursor', 'default');
            isApplyingProceeds = false;
            return;
        }

        console.log('DEBUG: Validated Proceeds Amount:', proceedsAmount);

        $button.prop('disabled', true).text('Applying...');
        $('body').css('cursor', 'wait');

var ajaxData = {
    action: 'apply_proceeds_balance_via_ajax',
    _wpnonce: cpw_proceeds_ajax.nonce, 
    proceeds_amount: proceedsAmount
};

        $.ajax({
            type: 'POST',
            url: cpw_proceeds_ajax.ajax_url,
            data: ajaxData,
            success: function(response){

                if (response.success && response.data && response.data.message) {
                    $errorMessageSpan.html('<p class="woocommerce-message">' + response.data.message + '</p>').css('color', 'green');
                } else if (response.data && response.data.message) {
                    $errorMessageSpan.html('<p class="woocommerce-error">' + response.data.message + '</p>').css('color', 'red');
                } else {
                    $errorMessageSpan.html('<p class="woocommerce-info">' + (response.message || 'Processing completed, reloading page...') + '</p>').css('color', 'blue');
                }
                

                window.location.reload(); 
            },
            error: function(jqXHR, textStatus, errorThrown){
                console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                $errorMessageSpan.html('<p class="woocommerce-error">' + cpw_proceeds_ajax.message_error_general + '</p>').css('color', 'red');
                $button.prop('disabled', false).text(originalButtonText);
                $('body').css('cursor', 'default');
                isApplyingProceeds = false;
            },
            complete: function(){
                $button.prop('disabled', false).text(originalButtonText);
                $('body').css('cursor', 'default');
                isApplyingProceeds = false;
                console.log('DEBUG: AJAX complete (this might not always fire before reload).');

                setTimeout(function() {
                    if ($errorMessageSpan.find('.woocommerce-message').length || $errorMessageSpan.find('.woocommerce-info').length) {
                        $errorMessageSpan.html('');
                    }
                }, 5000);
            }
        });
    });
     
    //console.log('Attempted to attach click handler via document-level event delegation.');

    if ($('#apply_proceeds_balance_button').length) {
        //console.log('Button was found immediately by jQuery selector (initial DOM).');
    } else {
        //console.log('Button was NOT found immediately by jQuery selector. Likely added dynamically, which is why delegation is needed.');
    }
});