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
    this.paymentStartTime = null; // Track payment start time
    this.successHandled = false; // Prevent double handling
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
    const isSmallScreen = window.innerWidth <= 768 || window.innerHeight <= 768;

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
    this.setupPaymentPolling(); // NEW: Setup success polling

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

  // NEW: Setup polling to check payment status
  setupPaymentPolling() {
    this.log("Setting up payment status polling...");

    // Check for payment success every 2 seconds when payment is in progress
    this.statusCheckInterval = setInterval(() => {
      if (
        this.paymentInProgress &&
        this.currentPaymentId &&
        !this.successHandled
      ) {
        this.checkPaymentStatus();
      }
    }, 2000);
  }

  // NEW: Check payment status via server
  async checkPaymentStatus() {
    if (!this.currentPaymentId) return;

    try {
      const formData = new FormData();
      formData.append("action", "check_payment_status");
      formData.append("payment_id", this.currentPaymentId);
      formData.append("nonce", rishumit_ajax.nonce);

      const response = await fetch(rishumit_ajax.ajax_url, {
        method: "POST",
        body: formData,
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (response.ok) {
        const result = await response.json();
        this.log("Payment status check:", result);

        if (result.success && result.status === "paid") {
          this.log("Payment confirmed as successful via polling!");
          this.handlePollingSuccess(result);
        }
      }
    } catch (error) {
      this.log("Status check error (non-critical):", error.message);
    }
  }

  // NEW: Handle success detected via polling
  handlePollingSuccess(result) {
    if (this.successHandled) return;

    this.successHandled = true;
    clearInterval(this.statusCheckInterval);

    this.log("Handling polling-detected success");
    this.resetPaymentState();

    // Show success message before redirect
    this.showSuccessMessage("התשלום בוצע בהצלחה! מעביר לעמוד אישור...");

    setTimeout(() => {
      const confirmationNumber = result.confirmation_number || "";
      const paymentMethod = result.payment_method || "card";
      const strapiId = this.currentStrapiId || "";

      let redirectUrl = `/thank-you?confirmation=${confirmationNumber}&method=${paymentMethod}`;
      if (strapiId) {
        redirectUrl += `&id=${strapiId}`;
      }

      window.location.href = redirectUrl;
    }, 2000);
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
              this.paymentProcessed.add(paymentData.payment_id);
              this.currentPaymentId = paymentData.payment_id; // Store for polling

              const delay = this.isMobile ? 500 : 100;
              setTimeout(() => {
                this.loadSDKAndProcess(paymentData.payment_id);
              }, delay);
            }
          }
        } catch (e) {
          // Not JSON
        }
      }
    });

    this.log("Event listeners bound");
  }

  loadSDKAndProcess(paymentId) {
    this.log("loadSDKAndProcess called with ID:", paymentId);
    this.paymentStartTime = Date.now(); // Track when payment starts

    if (this.paymentInProgress) {
      this.log("Payment already in progress, skipping");
      return;
    }

    this.paymentInProgress = true;
    this.successHandled = false; // Reset success handler
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

    const existingScript = document.querySelector('script[src*="meshulam"]');
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
          this.handlePaymentSuccess(response);
        },
        onFailure: (response) => {
          this.error("SDK onFailure triggered:", response);
          this.handlePaymentFailure(response);
        },
        onError: (response) => {
          this.error("SDK onError triggered:", response);
          this.handlePaymentError(response);
        },
        onTimeout: (response) => {
          this.error("SDK onTimeout triggered:", response);
          this.handlePaymentTimeout(response);
        },
        onWalletChange: (state) => {
          this.log("Wallet state changed:", state);
          this.handleWalletChange(state);
        },
        onPaymentStart: (response) => {
          this.log("Payment started in SDK:", response);
          this.paymentStartTime = Date.now();
        },
        onPaymentCancel: (response) => {
          this.log("Payment cancelled:", response);
          this.handlePaymentCancel(response);
        },
        // NEW: Additional mobile-specific events
        onPaymentComplete: (response) => {
          this.log("Payment complete (alternative event):", response);
          // Some mobile browsers might trigger this instead of onSuccess
          if (!this.successHandled) {
            this.handlePaymentSuccess(response);
          }
        },
      },
    };

    try {
      this.log("Initializing with config:", config);
      growPayment.init(config);
      this.log("Meshulam SDK configured successfully");
      this.sdkInitialized = true;
      this.initializationRetries = 0;

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
        throw new Error(response.message || "Failed to create payment process");
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
      const formData = new FormData();
      formData.append("action", "create_payment_process");
      formData.append("payment_id", paymentId);
      formData.append("nonce", rishumit_ajax.nonce);

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

  // Enhanced event handlers
  handlePaymentSuccess(response) {
    if (this.successHandled) {
      this.log("Success already handled, ignoring duplicate");
      return;
    }

    this.successHandled = true;
    clearInterval(this.statusCheckInterval);

    this.log("Payment completed successfully:", response);

    // Mobile: Show success message first, then redirect
    if (this.isMobile) {
      this.showSuccessMessage("התשלום בוצע בהצלחה! מעביר לעמוד אישור...");

      setTimeout(() => {
        this.performSuccessRedirect(response);
      }, 2000); // 2 second delay for mobile
    } else {
      this.performSuccessRedirect(response);
    }
  }

  performSuccessRedirect(response) {
    this.resetPaymentState();

    const confirmationNumber = response.data?.confirmation_number || "";
    const paymentMethod = response.data?.payment_method || "";
    const strapiId = this.currentStrapiId || "";

    let redirectUrl = `/thank-you?confirmation=${confirmationNumber}&method=${paymentMethod}`;
    if (strapiId) {
      redirectUrl += `&id=${strapiId}`;
    }

    this.log("Redirecting to:", redirectUrl);
    window.location.href = redirectUrl;
  }

  handlePaymentFailure(response) {
    if (this.successHandled) return; // Don't show failure if success was already handled

    this.resetPaymentState();
    const message = response.message || "שגיאה לא ידועה";

    // Check if this is actually a successful payment that Meshulam reported as failure
    if (this.paymentStartTime && Date.now() - this.paymentStartTime > 5000) {
      this.log(
        "Payment took longer than 5 seconds, checking status before showing error"
      );

      // Give it a moment, then check if payment actually succeeded
      setTimeout(() => {
        this.checkPaymentStatus();
      }, 1000);

      // Show a different message for potential false failures
      this.showError(
        "מעבד את התשלום... אם התשלום הושלם, תועבר לעמוד האישור בקרוב"
      );
      return;
    }

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

    // Don't immediately show timeout error - check payment status first
    this.log("Payment timeout, checking actual status...");
    this.checkPaymentStatus();

    // Show timeout message with option to check status
    this.showError("זמן התשלום פג. בודק סטטוס התשלום...");
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

    if (error.message.includes("Network") || error.message.includes("fetch")) {
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
    clearInterval(this.statusCheckInterval);

    if (this.isMobile) {
      document.body.style.overflow = "";
      document.documentElement.style.overflow = "";
    }
  }

  // UI helpers
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

  // NEW: Show success message
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
