<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Shopify</title>

    <!-- Fonts -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.4.0/css/font-awesome.min.css" rel='stylesheet'
          type='text/css'>
    <link href="https://fonts.googleapis.com/css?family=Lato:100,300,400,700" rel='stylesheet' type='text/css'>

    <!-- Styles -->
    <link href="{{url('css/uptown.css')}}" rel='stylesheet' type='text/css'>
    <link href="{{url('css/style.css')}}" rel='stylesheet' type='text/css'>
    
    @yield('after-style-end')

    <!-- JavaScripts -->
    <script src="https://unpkg.com/axios@0.26.1/dist/axios.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/2.1.4/jquery.min.js"></script>
    <script src="https://unpkg.com/@shopify/app-bridge@3.0.1"></script>
    <script src="https://unpkg.com/@shopify/app-bridge-utils@3.4.3"></script>
    <script>
        //check if we have any redirect backs
        const redirect_url = document.cookie.match('(^|;)\\s*redirect_back_url\\s*=\\s*([^;]+)')?.pop() || false;
        
        var AppBridge = window['app-bridge'];

        //get host parameter
        const queryString = window.location.search;
        const urlParams = new URLSearchParams(queryString);
        var host = urlParams.get('host');

        if(!host || host == ''){
            host = '{{$shop->shop_origin}}';
        }

        var Actions = AppBridge.actions;
        var createApp = AppBridge.default;
        var ShopifyApp = createApp({
            apiKey: '{{config('shopify.api_key')}}',
            shopOrigin: '{{$shop->shop_origin}}',
            host: host,
            debug: true,
            forceRedirect: true
        });

        var AppUtils = window['app-bridge-utils'];
        var getSessionToken = AppUtils.getSessionToken;
        var appDiv = null;

        // Axios instance with seesionToken attached to all requests
        var ax = axios.create();
        ax.interceptors.request.use(  
            function (config) {  
                return getSessionToken(window.ShopifyApp)  // requires an App Bridge instance
                .then((token) => {  
                    // append your request headers with an authenticated token
                    config.headers['Authorization'] = `Bearer ${token}`;  
                    return config;  
                });
            }
        );

        /* LOADING BAR */
        const loading = Actions.Loading.create(ShopifyApp);

        function startLoading() {
            let loader = document.getElementById('app-loader');
            if (!loader) {
                return;
            }

            loader.classList.remove('hidden');
        }

        function stopLoading() {
            let loader = document.getElementById('app-loader');
            if (!loader) {
                return;
            }

            loader.classList.add('hidden');
        }
        /* LOADING BAR END */
        
        /* TOAST */
        const toast = Actions.Toast.create(ShopifyApp, { duration: 2000 });

        function showToast(options) {
            toast.set(options);
            toast.dispatch(Actions.Toast.Action.SHOW);
        }
        /* TOAST END */
        
        const redirect = Actions.Redirect.create(ShopifyApp);
        redirect.subscribe(
            Actions.Redirect.Action.REMOTE,
            (payload) => {
                // Do something with the redirect
                return true;
            }
        );

        // -- Buttons
        /* SAVE BUTTON */
        const buttonSave = Actions.Button.create(
            ShopifyApp,
            {
                label: '{{trans('app.settings.save_settings')}}',
                disabled: true
            }
        );

        buttonSave.subscribe('click', () => {
            saveSettings(document.getElementById('setting-form'));
        });

        function saveBtnDisabled(status) {
            buttonSave.set({disabled: status});
        }

        function saveSettings(data) {
            startLoading();
            ax(
                {
                    method: 'post',
                    url: data.action,
                    data: new FormData(data),
                    headers: {'Content-Type': 'multipart/form-data' }
                }
            ).then(
                response => {
                    if (response.status === 200) {
                        showToast({
                            message: response.data.message,
                            isError: response.data.status === 'error'
                        });

                        if (typeof response.data.html !== 'undefined') {
                            updateInnerHtml(appDiv, response.data.html);
                        }

                        // if we changing locale need to update UI strings
                        if (response.data.status !== 'error' && data.action == '{{route('shopify.update-locale')}}') {
                            getTranslationStrings();
                        }

                    } else {
                        showToast({
                            message: 'Something went wrong',
                            isError: true
                        });
                    }
                    stopLoading();
                }
            ).catch(
                error => {
                    stopLoading();
                }
            );

            return;
        }

        function getTranslationStrings() {
            ax.get('{{route('shopify.button-translations')}}')
                .then(response => {
                    if (response.data.status == 'OK') {
                        updateUITranslation(response.data.data);
                        // Language change is only possible in Generic settings page so we can use its title to set Page title in correct language
                        titleBar.set({
                            title: buttonOtherSettings.label
                        });
                    }
                });
        }

        function updateUITranslation(data) {
            Object.keys(data).forEach(function(key) {
                if (key == 'value') {
                    updateStrings(data[key]);
                }

                if (key == 'label') {
                    updateButtons(data[key]);
                }
            });

            // this needs separate update
            if (buttonTestMode !== undefined){
                buttonTestMode.set({
                    'label': getTestModeBtnText()
                });
            }
        }

        function updateStrings(data) {
            Object.keys(data).forEach(function(key) {
                UIStrings[key] = data[key];
            });
        }

        function updateButtons(data) {
            Object.keys(data).forEach(function(key) {
                UICollection[key].set({
                    'label': data[key]
                });
            });
        }
        /* SAVE BUTTON END */

        /* NEWS BUTTON */
        const buttonNews = Actions.Button.create(ShopifyApp, {
            label: '{{trans('app.settings.latest-news')}}',
        });

        buttonNews.subscribe('click', () => {
            loadView(
                '{{route('shopify.latest-news')}}',
                () => {
                    saveBtnDisabled(true);
                    titleBar.set({
                        title: buttonNews.label
                    });
                }
            );
        });
        /* NEWS BUTTON END */
        
        /* SUPPORT BUTTON */
        @if (config('shopify.support_url'))
        const buttonSupport = Actions.Button.create(ShopifyApp, {
            label: '{{trans('app.settings.support')}}',
        });

        buttonSupport.subscribe('click', () => {
              redirect.dispatch(Actions.Redirect.Action.REMOTE, {
                url: '{{config('shopify.support_url')}}',
                newContext: true,
              });
        });
        @endif
        /* SUPPORT BUTTON END */

        /* TEST MODE BUTTON */
        @if (isset($type) && $type == "pakettikauppa")
        let UIStrings = {
            test_mode_enable_text: '{{trans('app.settings.testmode_on')}}',
            test_mode_disable_text: '{{trans('app.settings.testmode_off')}}'
        };
        let test_mode = @if($shop->test_mode) true @else false @endif;
            
        const buttonTestMode = Actions.Button.create(
            ShopifyApp, 
            { 
                label: getTestModeBtnText(),
                style: getTestModeBtnStyle() 
            }
        );

        buttonTestMode.subscribe('click', () => {
            switchMode();
        });

        function getTestModeBtnText() {
            return test_mode ? UIStrings.test_mode_disable_text : UIStrings.test_mode_enable_text;
        }

        function getTestModeBtnStyle() {
            return test_mode ? Actions.Button.Style.Danger : undefined;
        }
            
        function switchMode() {
            let data = {'test_mode': !test_mode};
            startLoading();
            ax(
                {
                    method: 'POST',
                    url: '{{route('shopify.update-test-mode')}}',
                    data: data,
                }
            ).then(
                response => {
                    if (response.status === 200 && response.data.status !== 'error') {
                        showToast({message: response.data.message, isError: false});
                        test_mode = !test_mode;
                        buttonTestMode.set({
                            label: getTestModeBtnText(),
                            style: getTestModeBtnStyle()
                        });
                    } else if (response.status === 200 && response.data.status === 'error') {
                        showToast({
                            message: response.data.message,
                            isError: true
                        });
                        console.error('RESPONSE ERROR:', response.data.message);
                    } else {
                        showToast({
                            message: 'Something went wrong',
                            isError: true
                        });
                    }
                    stopLoading();
                }
            ).catch(
                error => {
                    showToast({
                        message: 'Something went wrong',
                        isError: true
                    });
                    console.error('API ERROR:', error);
                    stopLoading();
                }
            );
        }
        @endif
        /* TEST MODE BUTTON END */

        /* SHIPMENT SETTINGS BUTTON */
        const buttonShipmentSettings = Actions.Button.create(
            ShopifyApp,
            {
                label: "{{trans('app.settings.shipment_settings')}}" 
            }
        );
        buttonShipmentSettings.subscribe('click', () => {
            loadView(
                '{{route('shopify.settings.shipping-link')}}', 
                () => {
                    saveBtnDisabled(false);
                    titleBar.set({
                        title: buttonShipmentSettings.label
                    });
                }
            );
        });
        /* SHIPMENT SETTINGS BUTTON END */

        /* PICKUP POINTS SETTINGS BUTTON */
        const buttonPickupPointsSettings = Actions.Button.create(
            ShopifyApp,
            { 
                label: "{{trans('app.settings.pickuppoints-settings')}}"
            }
        );

        buttonPickupPointsSettings.subscribe('click', () => {
            loadView(
                '{{route('shopify.settings.pickuppoints-link')}}',
                () => {
                    saveBtnDisabled(false);
                    titleBar.set({
                        title: buttonPickupPointsSettings.label
                    });
                }
            );
        });
        /* PICKUP POINTS SETTINGS BUTTON END */

        /* COMPANY INFORMATION SETTINGS BUTTON */
        const buttonCompanyInformationSettings = Actions.Button.create(
            ShopifyApp,
            { 
                label: "{{trans('app.settings.company_info')}}"
            }
        );

        buttonCompanyInformationSettings.subscribe('click', () => {
            loadView(
                '{{route('shopify.settings.sender-link')}}',
                () => {
                    saveBtnDisabled(false);
                    titleBar.set({
                        title: buttonCompanyInformationSettings.label
                    });
                }
            );
        });
        /* COMPANY INFORMATION SETTINGS BUTTON END */

        /* API SETTINGS BUTTON */
        const buttonApiSettings = Actions.Button.create(
            ShopifyApp,
            {
                label: "{{trans('app.settings.api-settings-'.($type??'pakettikauppa'))}}"
            }
        );

        buttonApiSettings.subscribe('click', () => {
            loadView(
                '{{route('shopify.settings.api-link')}}',
                () => {
                    saveBtnDisabled(false);
                    titleBar.set({
                        title: buttonApiSettings.label
                    });
                }
            );
        });
        /* API SETTINGS BUTTON END */

        /* OTHER SETTINGS BUTTON */
        const buttonOtherSettings = Actions.Button.create(
            ShopifyApp,
            { 
                label: "{{trans('app.settings.generic-settings')}}"
            }
        );

        buttonOtherSettings.subscribe('click', () => {
            loadView(
                '{{route('shopify.settings.generic-link')}}',
                () => {
                    saveBtnDisabled(false);
                    titleBar.set({
                        title: buttonOtherSettings.label
                    });
                }
            );
        });
        /* OTHER SETTINGS BUTTON END */
            
        /* VIEWS FUNCTIONS */
        // Calls api to get view html
        function loadView(viewUrl, callback = null) {
            startLoading();
            ax.get(viewUrl)
                .then(response => {
                    updateInnerHtml(appDiv, response.data);
                    if (callback) {
                        callback();
                    }
                    stopLoading();
                })
                .catch(error => {
                    stopLoading();
                });
        }

        // Replaces targets inner html with supplied html, evaling javascript code
        function updateInnerHtml(target, data) {
            target.innerHTML = data;
            let scripts = target.getElementsByTagName('script');
            Object.keys(scripts).forEach(key => {
                eval(scripts[key].innerText);
            });
        }
        /* VIEWS FUNCTIONS END */
            
        // Register buttons to shoppify app
        const optionsBtnGroup = Actions.ButtonGroup.create(
            ShopifyApp, 
            {
                label: '{{trans('app.settings.settings')}}',
                buttons: [
                    buttonShipmentSettings, buttonPickupPointsSettings, buttonCompanyInformationSettings,
                     buttonApiSettings, buttonOtherSettings
                ]
            }
        );
        
        const buttons = [];
        if (typeof(buttonTestMode) != "undefined"){
            buttons.push(buttonTestMode);
        }
        buttons.push(buttonNews);
        buttons.push(optionsBtnGroup);
        if (typeof(buttonSupport) != "undefined"){
            buttons.push(buttonSupport);
        }
        const titleBar = Actions.TitleBar.create(ShopifyApp, {
            title: 'My page title',
            buttons: {
                primary: buttonSave,
                secondary: buttons,
            },
        });

        const UICollection = {
            buttonSave: buttonSave,
            buttonNews: buttonNews,
            optionsBtnGroup: optionsBtnGroup,
            buttonShipmentSettings: buttonShipmentSettings, 
            buttonPickupPointsSettings: buttonPickupPointsSettings,
            buttonCompanyInformationSettings: buttonCompanyInformationSettings,
            buttonApiSettings: buttonApiSettings,
            buttonOtherSettings: buttonOtherSettings,
            titleBar: titleBar
        };
        /* Wait for HTML DOM before loading first page */
        document.addEventListener('DOMContentLoaded', e => {
            
            if (redirect_url){
                //clear redirect cookie
                document.cookie = "redirect_back_url=null;expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/;SameSite=None; Secure";
                redirect.dispatch(Actions.Redirect.Action.APP, decodeURIComponent(redirect_url));
            }
            
            appDiv = document.getElementById('app-page');

            // Check if there is custom content loaded
            if (document.getElementById('custom-page')) {
                customPageInit(); // this function must be created on custom page
                return;
            }

            // No custom content - load news page
            buttonNews.dispatch(Actions.Button.Action.CLICK);
        });
        
        
        function toggle_div(self, id) {
            if (!self.checked) {
                document.getElementById(id).style.display = "none";
            } else {
                document.getElementById(id).style.display = "block";
            }
        }
    </script>
    @yield('after-scripts-end')
</head>
<body id="app-layout">
    <div id="app-page">
        @yield('content')
    </div>
    <div id="app-loader" class="loading hidden">
        <img class="spinner" src="{{url('/img/ajax-loader.gif')}}">
    </div>
</body>
</html>
