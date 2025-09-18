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
    this.setupPaymentPolling();

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

  setupPaymentPolling() {
    this.log("Setting up payment status polling...");
    this.statusCheckInterval = setInterval(() => {
      if (
        this.paymentInProgress &&
        this.currentPaymentId &&
        !this.successHandled
      ) {
        this.checkPaymentStatus();
      }
    }, 10000);
  }

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

  handlePollingSuccess(result) {
    if (this.successHandled) return;

    this.successHandled = true;
    clearInterval(this.statusCheckInterval);

    this.log("Handling polling-detected success");
    this.resetPaymentState();

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
              this.currentPaymentId = paymentData.payment_id;

              const delay = this.isMobile ? 500 : 100;
              setTimeout(() => {
                this.loadSDKAndProcess(paymentData.payment_id);
              }, delay);
            }
          }
        } catch (e) {
          // Not JSON response
        }
      }
    });

    this.log("Event listeners bound");
  }

  loadSDKAndProcess(paymentId) {
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
    // Remove any existing Meshulam scripts to prevent conflicts
    const existingScripts = document.querySelectorAll(
      'script[src*="meshulam"], script[src*="gs.min.js"], script[src*="mp.min.js"]'
    );
    existingScripts.forEach((script) => script.remove());

    const script = document.createElement("script");
    script.type = "text/javascript";
    script.async = true;
    script.src = "https://cdn.meshulam.co.il/sdk/gs.min.js";
    script.id = "meshulam-sdk";

    script.onload = () => {
      this.log("Meshulam SDK loaded successfully");
      this.sdkLoaded = true;

      const delay = this.isMobile ? 1500 : 300;
      setTimeout(() => {
        this.configureSDK(() => {
          this.processPayment(paymentId);
        });
      }, delay);
    };

    script.onerror = (error) => {
      this.error("Failed to load Meshulam SDK:", error);
      this.showError("שגיאה בטעינת מערכת התשלומים");
      this.resetPaymentState();
    };

    document.head.appendChild(script);
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
          this.showError("שגיאה בטעינת מערכת התשלומים - SDK לא זמין");
          this.resetPaymentState();
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
        onPaymentComplete: (response) => {
          this.log("Payment complete (alternative event):", response);
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
        this.resetPaymentState();
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
        // Validate authCode format
        if (!this.validateAuthCode(response.authCode)) {
          throw new Error("Invalid authCode format received");
        }

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

  // NEW: Validate authCode format
  validateAuthCode(authCode) {
    if (!authCode || typeof authCode !== "string") {
      this.error("AuthCode is empty or not a string:", authCode);
      return false;
    }

    // Basic validation - authCode should be a non-empty string
    if (authCode.length < 10) {
      this.error("AuthCode too short:", authCode);
      return false;
    }

    // Check for obvious invalid characters or formats
    if (authCode.includes("undefined") || authCode.includes("null")) {
      this.error("AuthCode contains invalid values:", authCode);
      return false;
    }

    // Handle URL-encoded authCodes (common with Meshulam)
    if (authCode.includes("%")) {
      try {
        const decoded = decodeURIComponent(authCode);
        this.log(
          "Validating URL-encoded authCode. Original:",
          authCode,
          "Decoded:",
          decoded
        );
        return decoded.length >= 10;
      } catch (e) {
        this.error("Failed to decode URL-encoded authCode:", authCode, e);
        return false;
      }
    }

    return true;
  }

  async renderPaymentWithRetry(authCode, retryCount = 0) {
    try {
      this.log(`Attempting to render payment (attempt ${retryCount + 1})`);

      // Decode the authCode if it contains URL encoding
      let decodedAuthCode = authCode;
      if (authCode && authCode.includes("%")) {
        try {
          decodedAuthCode = decodeURIComponent(authCode);
          this.log("AuthCode decoded from:", authCode, "to:", decodedAuthCode);
        } catch (decodeError) {
          this.error("Failed to decode authCode:", decodeError);
          // Use original if decode fails
          decodedAuthCode = authCode;
        }
      }

      // Additional validation before rendering
      if (!this.validateAuthCode(decodedAuthCode)) {
        throw new Error("Invalid authCode - cannot render payment");
      }

      growPayment.renderPaymentOptions(decodedAuthCode);
      this.log("Payment options rendered successfully");
    } catch (error) {
      this.error(`Render attempt ${retryCount + 1} failed:`, error);

      if (retryCount < 2) {
        const delay = this.isMobile ? 1500 : 700;
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

    // Validate payment ID
    if (isNaN(paymentId) || paymentId <= 0) {
      throw new Error("Invalid payment ID format");
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

      const result = await response.json();

      // Enhanced response validation
      if (!result.success) {
        throw new Error(
          result.message || "Server returned unsuccessful response"
        );
      }

      if (!result.authCode) {
        throw new Error("No authCode received from server");
      }

      return result;
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
    clearInterval(this.statusCheckInterval);

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
    if (this.successHandled) return;

    // Enhanced failure handling
    const message = response.message || "שגיאה לא ידועה";
    this.log("Payment failure details:", response);

    // Check if this might be a false failure
    if (this.paymentStartTime && Date.now() - this.paymentStartTime > 5000) {
      this.log(
        "Payment took longer than 5 seconds, checking status before showing error"
      );

      setTimeout(() => {
        this.checkPaymentStatus();
      }, 1000);

      this.showError(
        "מעבד את התשלום... אם התשלום הושלם, תועבר לעמוד האישור בקרוב"
      );
      return;
    }

    // Handle specific error messages
    if (message.includes("הלינק שנשלח אינו תקין")) {
      this.showError("שגיאה בקישור התשלום. אנא רענן את הדף ונסה שוב");
    } else {
      this.showError("התשלום נכשל: " + message);
    }

    this.resetPaymentState();
  }

  handlePaymentError(response) {
    if (this.successHandled) return;

    this.resetPaymentState();
    const message = response.message || "שגיאה טכנית";
    this.showError("שגיאה בתשלום: " + message);
  }

  handlePaymentTimeout(response) {
    if (this.successHandled) return;

    this.log("Payment timeout, checking actual status...");
    this.checkPaymentStatus();
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

      const retryDelay = this.isMobile ? 3000 : 1500;
      setTimeout(() => {
        this.configureSDK(() => {
          try {
            if (this.validateAuthCode(authCode)) {
              growPayment.renderPaymentOptions(authCode);
            } else {
              throw new Error("Invalid authCode on retry");
            }
          } catch (secondError) {
            this.showError(errorMsg + " - נסה לרענן את הדף");
            this.resetPaymentState();
          }
        });
      }, retryDelay);
    } else {
      this.showError(errorMsg + " - אנא רענן את הדף ונסה שוב");
      this.resetPaymentState();
    }
  }

  handleProcessError(error) {
    let errorMessage = "שגיאה ביצירת תהליך התשלום";

    if (error.message.includes("Network") || error.message.includes("fetch")) {
      errorMessage = "בעיית רשת. אנא בדק את החיבור לאינטרנט";
    } else if (error.message.includes("timeout")) {
      errorMessage = "זמן הטעינה חרג. אנא נסה שוב";
    } else if (error.message.includes("Invalid authCode")) {
      errorMessage = "שגיאה בקוד התשלום. אנא רענן את הדף";
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
