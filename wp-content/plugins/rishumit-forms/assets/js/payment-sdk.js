(function () {
  if (window.rishumitPaymentSDKLoaded) {
    console.log("[PaymentSDK] Script already loaded, exiting");
    return; // This will exit the entire script execution
  }
  window.rishumitPaymentSDKLoaded = true;

  if (window.rishumitPaymentSDK) {
    console.log("[PaymentSDK] Already initialized, skipping duplicate");
    // Don't initialize again if already exists
  } else {
    class RishumitPaymentSDK {
      constructor() {
        this.sdkLoaded = false;
        this.sdkInitialized = false;
        this.paymentInProgress = false;
        this.paymentProcessed = new Set();
        this.initializationRetries = 0;
        this.maxRetries = 3;
        this.hasRetried = false;
        this.isMobile = this.detectMobileDevice();
        this.debugMode = true;
        this.paymentStartTime = null;
        this.successHandled = false;
        this.init();
      }

      detectMobileDevice() {
        const userAgent = navigator.userAgent.toLowerCase();
        const isMobileUA =
          /android|iphone|ipad|ipod|blackberry|iemobile|opera mini/i.test(
            userAgent
          );
        const isTouchDevice =
          "ontouchstart" in window || navigator.maxTouchPoints > 0;
        const isSmallScreen =
          window.innerWidth <= 768 || window.innerHeight <= 768;

        this.log("Mobile detection:", {
          isMobileUA,
          isTouchDevice,
          isSmallScreen,
          userAgent: navigator.userAgent,
        });

        return isMobileUA || (isTouchDevice && isSmallScreen);
      }

      log(...args) {
        if (this.debugMode) {
          console.log("[PaymentSDK]", new Date().toISOString(), ...args);
        }
      }

      error(...args) {
        console.error("[PaymentSDK ERROR]", new Date().toISOString(), ...args);
      }

      init() {
        this.log("RishumitPaymentSDK initializing...");
        this.log("Mobile device detected:", this.isMobile);

        this.ensureViewport();

        document.addEventListener("DOMContentLoaded", () => {
          this.bindFormEvents();
        });

        if (
          document.readyState === "complete" ||
          document.readyState === "interactive"
        ) {
          this.bindFormEvents();
        }
      }

      ensureViewport() {
        if (this.isMobile && !document.querySelector('meta[name="viewport"]')) {
          const viewport = document.createElement("meta");
          viewport.name = "viewport";
          viewport.content =
            "width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no";
          document.head.appendChild(viewport);
          this.log("Viewport meta tag added for mobile");
        }
      }

      bindFormEvents() {
        this.log("Binding form events...");

        if (typeof jQuery === "undefined") {
          this.error("jQuery not loaded - payment detection will not work");
          return;
        }

        // Before AJAX is sent, add URL parameters ONLY for OTP form
        jQuery(document).ajaxSend((event, jqXHR, settings) => {
          if (settings.url && settings.url.includes("admin-ajax.php")) {
            // Check if this is an OTP form submission (check if page has invoice_number in URL)
            const urlParams = new URLSearchParams(window.location.search);
            const hasInvoiceNumber = urlParams.has('invoice_number');

            if (hasInvoiceNumber && settings.data && typeof settings.data === 'string' && settings.data.includes('elementor_pro_forms_send_form')) {
              // This is likely the OTP form - preserve URL params
              const params = {};
              for (const [key, value] of urlParams.entries()) {
                params[key] = value;
              }

              console.log("📋 OTP Form detected - Attaching URL parameters:", params);
              settings.data += '&url_params=' + encodeURIComponent(JSON.stringify(params));
            }
          }
        });

        jQuery(document).ajaxSuccess((event, xhr, settings) => {
          if (
            settings.url &&
            settings.url.includes("admin-ajax.php") &&
            xhr.responseText
          ) {
            try {
              const response = JSON.parse(xhr.responseText);
              let paymentData = null;

              if (response?.data?.data?.show_payment) {
                paymentData = response.data.data;
              } else if (response?.data?.show_payment) {
                paymentData = response.data;
              } else if (response?.show_payment) {
                paymentData = response;
              }

              if (
                paymentData &&
                paymentData.show_payment &&
                paymentData.payment_id
              ) {
                if (!this.paymentProcessed.has(paymentData.payment_id)) {
                  this.log("PAYMENT TRIGGER DETECTED:", paymentData);

                  const delay = this.isMobile ? 500 : 100;
                  setTimeout(() => {
                    this.loadSDKAndProcess(paymentData.payment_id);
                  }, delay);
                }
              }

              // Handle OTP form redirect
              let redirectUrl = null;
              if (response?.data?.data?.redirect_url) {
                redirectUrl = response.data.data.redirect_url;
              } else if (response?.data?.redirect_url) {
                redirectUrl = response.data.redirect_url;
              } else if (response?.redirect_url) {
                redirectUrl = response.redirect_url;
              }

              if (redirectUrl) {
                console.log("════════════════════════════════════════");
                console.log("🔀 OTP FORM REDIRECT DETECTED");
                console.log("Redirect URL:", redirectUrl);
                console.log("Redirecting in 1.5 seconds...");
                console.log("════════════════════════════════════════");

                setTimeout(() => {
                  window.location.href = redirectUrl;
                }, 500);
              }
            } catch (e) {
              // Not JSON
            }
          }
        });

        this.log("Event listeners bound");
      }

      loadSDKAndProcess(paymentId) {
        if (this.paymentProcessed.has(paymentId)) {
          this.log("Payment already processed for ID:", paymentId);
          return; // Exit early
        }

        this.paymentProcessed.add(paymentId);

        this.log("loadSDKAndProcess called with ID:", paymentId);
        this.paymentStartTime = Date.now();

        if (this.paymentInProgress) {
          this.log("Payment already in progress, skipping");
          return;
        }

        this.paymentInProgress = true;
        this.successHandled = false;
        this.hasRetried = false;

        if (this.sdkLoaded && this.sdkInitialized) {
          this.processPayment(paymentId);
          return;
        }

        if (this.sdkLoaded && !this.sdkInitialized) {
          this.configureSDK(() => {
            this.processPayment(paymentId);
          });
          return;
        }

        this.loadMeshulamSDK(paymentId);
      }

      loadMeshulamSDK(paymentId) {
        const script = document.createElement("script");
        script.type = "text/javascript";
        script.async = true;
        script.src = "https://cdn.meshulam.co.il/sdk/gs.min.js";

        script.onload = () => {
          this.log("Meshulam SDK loaded successfully");
          this.sdkLoaded = true;

          const delay = this.isMobile ? 1000 : 200;
          setTimeout(() => {
            this.configureSDK(() => {
              this.processPayment(paymentId);
            });
          }, delay);
        };

        script.onerror = (error) => {
          this.error("Failed to load Meshulam SDK:", error);
          this.showError("שגיאה בטעינת מערכת התשלומים");
          this.paymentInProgress = false;
        };

        const existingScript = document.querySelector(
          'script[src*="meshulam"]'
        );
        if (existingScript) {
          existingScript.remove();
        }

        const firstScript = document.getElementsByTagName("script")[0];
        firstScript.parentNode.insertBefore(script, firstScript);
      }

      configureSDK(callback) {
        this.log("Configuring Meshulam SDK...");

        const checkSDKAvailable = (retryCount = 0) => {
          if (typeof growPayment === "undefined") {
            if (retryCount < this.maxRetries) {
              const retryDelay = this.isMobile ? 1500 : 800;
              setTimeout(() => {
                checkSDKAvailable(retryCount + 1);
              }, retryDelay);
              return;
            } else {
              this.showError("שגיאה בטעינת מערכת התשלומים");
              this.paymentInProgress = false;
              return;
            }
          }

          this.initializeSDK(callback);
        };

        checkSDKAvailable();
      }

      initializeSDK(callback) {
        const config = {
          environment:
            window.WP_ENVIRONMENT_TYPE === "PRODUCTION" ? "PRODUCTION" : "DEV",
          version: 1,
          mobile: this.isMobile
            ? {
                theme: "light",
                animation: false,
                fullscreen: true,
                preventZoom: true,
                optimizeForMobile: true,
              }
            : undefined,
          events: {
            onSuccess: (response) => {
              this.log("SDK onSuccess triggered:", response);
              // Log to backend immediately when SDK triggers success
              this.logMeshulamResponse(response, 'SDK_onSuccess_triggered', null);
              this.handlePaymentSuccess(response);
            },
            onFailure: (response) => {
              this.error("SDK onFailure triggered:", response);
              this.logMeshulamResponse(response, 'SDK_onFailure_triggered', null);
              this.handlePaymentFailure(response);
            },
            onError: (response) => {
              this.error("SDK onError triggered:", response);
              this.logMeshulamResponse(response, 'SDK_onError_triggered', null);
              this.handlePaymentError(response);
            },
            onTimeout: (response) => {
              this.error("SDK onTimeout triggered:", response);
              this.logMeshulamResponse(response, 'SDK_onTimeout_triggered', null);
              this.handlePaymentTimeout(response);
            },
            onWalletChange: (state) => {
              this.log("Wallet state changed:", state);
              this.logMeshulamResponse({state: state}, 'wallet_state_' + state, null);
              this.handleWalletChange(state);
            },
            onPaymentStart: (response) => {
              this.log("Payment started in SDK:", response);
              this.logMeshulamResponse(response, 'Payment_started', null);
              this.paymentStartTime = Date.now();
            },
            onPaymentCancel: (response) => {
              this.log("Payment cancelled:", response);
              this.logMeshulamResponse(response, 'Payment_cancelled', null);
              this.handlePaymentCancel(response);
            },
            onPaymentComplete: (response) => {
              this.log("Payment complete (alternative event):", response);
              // Log to backend immediately when alternative completion event triggers
              this.logMeshulamResponse(response, 'SDK_onPaymentComplete_triggered. successHandled: ' + this.successHandled, null);
              if (!this.successHandled) {
                this.handlePaymentSuccess(response);
              }
            },
            // Catch-all for any other events Meshulam might fire
            onEvent: (eventName, data) => {
              this.log("Meshulam SDK event:", eventName, data);
              this.logMeshulamResponse({eventName: eventName, data: data}, 'SDK_onEvent_' + eventName, null);
            },
          },
        };

        try {
          this.log("Initializing with config:", config);
          growPayment.init(config);
          this.log("Meshulam SDK configured successfully");
          this.sdkInitialized = true;
          this.initializationRetries = 0;

          // Log all available growPayment methods for debugging
          if (typeof growPayment === 'object') {
            this.log("Available growPayment methods:", Object.keys(growPayment));
          }

          const waitTime = this.isMobile ? 1500 : 300;
          setTimeout(() => {
            if (callback) callback();
          }, waitTime);
        } catch (error) {
          this.error("Error configuring SDK:", error);

          if (this.initializationRetries < this.maxRetries) {
            this.initializationRetries++;
            const retryDelay = this.isMobile ? 2000 : 1000;
            setTimeout(() => {
              this.configureSDK(callback);
            }, retryDelay);
          } else {
            this.showError("שגיאה בהגדרת מערכת התשלומים");
            this.paymentInProgress = false;
          }
        }
      }

      async processPayment(paymentId) {
        this.log("processPayment called with ID:", paymentId);
        this.showLoader();

        try {
          const response = await this.createPaymentProcess(paymentId);
          this.log("Payment process response:", response);

          if (response.success && response.authCode) {
            this.log("Payment process created, authCode:", response.authCode);
            this.currentStrapiId = response.strapiId || paymentId;
            this.currentPhone = response.phone || "";

            if (response.successUrl) {
              this.storedSuccessUrl = response.successUrl;
              this.log("Stored success URL:", this.storedSuccessUrl);
            }

            // CHECK IF LOCAL MODE (authCode starts with LOCAL_TEST)
            if (response.authCode.startsWith('LOCAL_TEST_AUTH_CODE_')) {
              this.log("🔧 LOCAL MODE DETECTED: Auto-completing payment without Meshulam");
              this.hideLoader();

              // Simulate successful payment after short delay
              setTimeout(() => {
                this.handlePaymentSuccess({
                  data: {
                    confirmation_number: 'LOCAL_' + Date.now(),
                    payment_method: 'test',
                    transaction_id: response.transactionId || 'LOCAL_TXN_' + Date.now()
                  }
                });
              }, 1000);
              return;
            }

            if (typeof growPayment !== "undefined" && this.sdkInitialized) {
              this.log("Calling growPayment.renderPaymentOptions");

              try {
                if (this.isMobile) {
                  document.body.style.overflow = "hidden";
                  document.documentElement.style.overflow = "hidden";
                }

                await this.renderPaymentWithRetry(response.authCode);
              } catch (renderError) {
                this.error("Error rendering payment options:", renderError);
                this.handleRenderError(renderError, response.authCode);
              }
            } else {
              throw new Error("SDK not properly initialized");
            }
          } else {
            throw new Error(
              response.message || "Failed to create payment process"
            );
          }
        } catch (error) {
          this.error("Payment process error:", error);
          this.handleProcessError(error);
        }
      }

      async renderPaymentWithRetry(authCode, retryCount = 0) {
        try {
          this.log(`Attempting to render payment (attempt ${retryCount + 1})`);
          growPayment.renderPaymentOptions(authCode);
          this.log("Payment options rendered successfully");
        } catch (error) {
          if (retryCount < 2) {
            const delay = this.isMobile ? 1000 : 500;
            await new Promise((resolve) => setTimeout(resolve, delay));
            return this.renderPaymentWithRetry(authCode, retryCount + 1);
          } else {
            throw error;
          }
        }
      }

      async getFreshNonce() {
        try {
          const formData = new FormData();
          formData.append("action", "get_payment_nonce");

          const response = await fetch(rishumit_ajax.ajax_url, {
            method: "POST",
            body: formData,
            headers: {
              "X-Requested-With": "XMLHttpRequest",
            },
          });

          if (!response.ok) {
            throw new Error(`Failed to fetch nonce: ${response.status}`);
          }

          const data = await response.json();

          if (data.success && data.data && data.data.nonce) {
            return data.data.nonce;
          } else {
            throw new Error("Invalid nonce response");
          }
        } catch (error) {
          this.error("Error fetching fresh nonce:", error);
          // Fallback to the original nonce if fresh nonce fails
          return rishumit_ajax.nonce;
        }
      }

      async createPaymentProcess(paymentId) {
        if (!paymentId) {
          throw new Error("Payment ID is required");
        }

        if (typeof rishumit_ajax === "undefined") {
          throw new Error("AJAX configuration not available");
        }

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);

        try {
          // Fetch a fresh nonce first
          this.log("Fetching fresh nonce...");
          const freshNonce = await this.getFreshNonce();
          this.log("Fresh nonce received");

          const formData = new FormData();
          formData.append("action", "create_payment_process");
          formData.append("payment_id", paymentId);
          formData.append("nonce", freshNonce);

          const response = await fetch(rishumit_ajax.ajax_url, {
            method: "POST",
            body: formData,
            signal: controller.signal,
            headers: {
              "X-Requested-With": "XMLHttpRequest",
            },
          });

          clearTimeout(timeoutId);

          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }

          return await response.json();
        } catch (error) {
          clearTimeout(timeoutId);
          if (error.name === "AbortError") {
            throw new Error("Request timeout - please try again");
          }
          throw error;
        }
      }

      handlePaymentSuccess(response) {
        if (this.successHandled) {
          this.log("Success already handled, ignoring duplicate");
          return;
        }

        this.successHandled = true;
        this.log("Payment completed successfully:", response);

        if (this.isMobile) {
          this.showSuccessMessage("התשלום בוצע בהצלחה! מעביר לעמוד אישור...");
          setTimeout(() => {
            this.performSuccessRedirect(response);
          }, 2000);
        } else {
          this.performSuccessRedirect(response);
        }
      }

      performSuccessRedirect(response) {
        this.resetPaymentState();

        // Log that we entered performSuccessRedirect
        this.logMeshulamResponse(response, 'performSuccessRedirect_ENTRY', null);

        // Use the stored success URL first (this contains conversion_id and form)
        let redirectUrl = this.storedSuccessUrl;

        // Extract invoice number from Meshulam response
        // Meshulam returns it as "confirmation_number", but we'll pass it as "invoice_number" in URL
        const invoiceNumber = response.data?.confirmation_number || "";

        // Store invoice_number in sessionStorage so it persists across back/forward navigation
        if (invoiceNumber) {
          sessionStorage.setItem('rishumit_invoice_number', invoiceNumber);
          this.log("Stored invoice_number in sessionStorage:", invoiceNumber);
          this.logMeshulamResponse({"redirectUrl": redirectUrl, "invoiceNumber": invoiceNumber}, 'Stored invoice_number in sessionStorage', null);
        }

        // Log that we entered performSuccessRedirect
        this.logMeshulamResponse({"redirectUrl": redirectUrl, "invoiceNumber": invoiceNumber}, 'performSuccessRedirect_ENTRY2', null);

        if (!redirectUrl) {
          // Only fall back to building URL if no stored URL
          const strapiId = this.currentStrapiId || "";

          redirectUrl = `/thank-you?confirmation=${invoiceNumber}`;
          if (strapiId) {
            redirectUrl += `&id=${strapiId}`;
          }
          if (invoiceNumber) {
            redirectUrl += `&invoice_number=${invoiceNumber}`;
          }
          this.log("Built fallback redirect URL:", redirectUrl);
          this.logMeshulamResponse({"redirectUrl": redirectUrl, "invoiceNumber": invoiceNumber}, 'Built fallback redirect URL', null);
        } else {
          // Add invoice_number and phone to the stored URL
          if (invoiceNumber && !redirectUrl.includes("invoice_number=")) {
            const separator = redirectUrl.includes("?") ? "&" : "?";
            redirectUrl += `${separator}invoice_number=${invoiceNumber}`;
          }
          this.log(
            "Using stored success URL with invoice_number:",
            redirectUrl
          );
          this.logMeshulamResponse({"redirectUrl": redirectUrl, "invoiceNumber": invoiceNumber}, 'Built fallback redirect Using stored success URL with invoice_number', null);
        }

        this.log("Final redirect URL:", redirectUrl);
        this.logMeshulamResponse({"redirectUrl": redirectUrl, "invoiceNumber": invoiceNumber}, 'Final redirect URL', null);

        // Send Meshulam response and redirect URL to backend for logging
        this.logMeshulamResponse(response, 'performSuccessRedirect', redirectUrl);

        window.location.href = redirectUrl;
      }

      logMeshulamResponse(response, context = null, redirectUrl = null) {
        // Send the Meshulam response, context, and redirect URL to backend for logging
        const endpoint = '/wp-json/payment/v1/log-meshulam-response';

        const logData = {
          meshulam_response: response,
          context: context,
          redirect_url: redirectUrl
        };

        fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(logData)
        }).catch(error => {
          console.error('Failed to log Meshulam response:', error);
        });
      }

      handlePaymentFailure(response) {
        if (this.successHandled) return;
        this.resetPaymentState();
        const message = response.message || "שגיאה לא ידועה";
        this.showError("התשלום נכשל: " + message);
      }

      handlePaymentError(response) {
        if (this.successHandled) return;
        this.resetPaymentState();
        const message = response.message || "שגיאה טכנית";
        this.showError("שגיאה בתשלום: " + message);
      }

      handlePaymentTimeout(response) {
        if (this.successHandled) return;
        this.resetPaymentState();
        this.showError("זמן התשלום פג. אנא נסה שוב.");
      }

      handleWalletChange(state) {
        this.log("Wallet state:", state);
        if (state === "open") {
          this.hideLoader();
        } else if (state === "close" && !this.successHandled) {
          this.resetPaymentState();
        }
      }

      handlePaymentCancel(response) {
        if (this.successHandled) return;
        this.resetPaymentState();
        this.log("Payment cancelled by user");
      }

      handleRenderError(renderError, authCode) {
        const errorMsg = this.isMobile
          ? "שגיאה בהצגת אפשרויות התשלום במכשיר נייד"
          : "שגיאה בהצגת אפשרויות התשלום";

        if (!this.hasRetried) {
          this.hasRetried = true;
          this.sdkInitialized = false;

          const retryDelay = this.isMobile ? 2000 : 1000;
          setTimeout(() => {
            this.configureSDK(() => {
              try {
                growPayment.renderPaymentOptions(authCode);
              } catch (secondError) {
                this.showError(errorMsg);
                this.resetPaymentState();
              }
            });
          }, retryDelay);
        } else {
          this.showError(errorMsg);
          this.resetPaymentState();
        }
      }

      handleProcessError(error) {
        let errorMessage = "שגיאה ביצירת תהליך התשלום";

        if (
          error.message.includes("Network") ||
          error.message.includes("fetch")
        ) {
          errorMessage = "בעיית רשת. אנא בדק את החיבור לאינטרנט";
        } else if (error.message.includes("timeout")) {
          errorMessage = "זמן הטעינה חרג. אנא נסה שוב";
        }

        this.showError(errorMessage + ": " + error.message);
        this.resetPaymentState();
      }

      resetPaymentState() {
        this.paymentInProgress = false;
        this.hideLoader();

        if (this.isMobile) {
          document.body.style.overflow = "";
          document.documentElement.style.overflow = "";
        }
      }

      showLoader() {
        let loader = document.getElementById("payment-loader");
        if (!loader) {
          loader = document.createElement("div");
          loader.id = "payment-loader";
          loader.innerHTML = `
        <div class="payment-overlay" style="
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: rgba(0,0,0,0.7);
          display: flex;
          justify-content: center;
          align-items: center;
          z-index: 999999;
        ">
          <div class="payment-spinner" style="
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
          ">
            <div class="spinner" style="
              border: 4px solid #f3f3f3;
              border-top: 4px solid #3498db;
              border-radius: 50%;
              width: 40px;
              height: 40px;
              animation: spin 1s linear infinite;
              margin: 0 auto 10px;
            "></div>
            <p style="margin: 0;">מכין תשלום...</p>
          </div>
        </div>
      `;

          if (!document.getElementById("payment-spinner-styles")) {
            const style = document.createElement("style");
            style.id = "payment-spinner-styles";
            style.textContent = `
          @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
          }
        `;
            document.head.appendChild(style);
          }

          document.body.appendChild(loader);
        }
        loader.style.display = "block";
      }

      hideLoader() {
        const loader = document.getElementById("payment-loader");
        if (loader) {
          loader.style.display = "none";
        }
      }

      showSuccessMessage(message) {
        const existingSuccess = document.getElementById("payment-success");
        if (existingSuccess) {
          existingSuccess.remove();
        }

        const successDiv = document.createElement("div");
        successDiv.id = "payment-success";
        successDiv.innerHTML = `
      <div style="
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000000;
      ">
        <div style="
          background: white;
          padding: 30px;
          border-radius: 8px;
          text-align: center;
          max-width: 400px;
          width: 80%;
        ">
          <div style="color: #4CAF50; font-size: 48px; margin-bottom: 15px;">✅</div>
          <p style="margin: 0; color: #4CAF50; font-weight: bold; font-size: 16px;">${message}</p>
          <div style="margin-top: 20px;">
            <div style="
              border: 3px solid #4CAF50;
              border-top: 3px solid transparent;
              border-radius: 50%;
              width: 20px;
              height: 20px;
              animation: spin 1s linear infinite;
              margin: 0 auto;
            "></div>
          </div>
        </div>
      </div>
    `;
        document.body.appendChild(successDiv);
      }

      showError(message) {
        const existingError = document.getElementById("payment-error");
        if (existingError) {
          existingError.remove();
        }

        const errorDiv = document.createElement("div");
        errorDiv.id = "payment-error";
        errorDiv.innerHTML = `
      <div style="
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000000;
        padding: 20px;
        box-sizing: border-box;
      ">
        <div style="
          background: white;
          padding: 20px;
          border-radius: 8px;
          text-align: center;
          max-width: 400px;
          width: 100%;
        ">
          <p style="margin: 0 0 15px 0; color: #d32f2f; font-weight: bold;">${message}</p>
          <button onclick="this.closest('#payment-error').remove()" style="
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
          ">סגור</button>
        </div>
      </div>
    `;
        document.body.appendChild(errorDiv);

        setTimeout(() => {
          if (errorDiv.parentNode) {
            errorDiv.remove();
          }
        }, 10000);
      }
    }

    // Initialize
    document.addEventListener("DOMContentLoaded", () => {
      if (!window.rishumitPaymentSDK) {
        window.rishumitPaymentSDK = new RishumitPaymentSDK();
      }
    });

    if (
      document.readyState === "complete" ||
      document.readyState === "interactive"
    ) {
      if (!window.rishumitPaymentSDK) {
        window.rishumitPaymentSDK = new RishumitPaymentSDK();
      }
    }
  }
})();
