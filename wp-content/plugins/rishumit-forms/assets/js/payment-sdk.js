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
    this.debugMode = true; // Enable debug mode
    this.init();
  }

  detectMobileDevice() {
    const isMobile =
      /iPhone|iPad|iPod|Android|webOS|BlackBerry|IEMobile|Opera Mini/i.test(
        navigator.userAgent
      );
    const isTouchDevice =
      "ontouchstart" in window || navigator.maxTouchPoints > 0;
    const isSmallScreen = window.innerWidth <= 768;

    this.log("Mobile detection:", {
      isMobile,
      isTouchDevice,
      isSmallScreen,
      userAgent: navigator.userAgent,
      screenSize: `${window.innerWidth}x${window.innerHeight}`,
    });
    return isMobile || (isTouchDevice && isSmallScreen);
  }

  log(...args) {
    if (this.debugMode) {
      console.log("[PaymentSDK]", ...args);
    }
  }

  error(...args) {
    console.error("[PaymentSDK ERROR]", ...args);
  }

  init() {
    this.log("RishumitPaymentSDK initializing...");
    this.log("Mobile device detected:", this.isMobile);

    // Check for required dependencies
    this.checkDependencies();

    // Add mobile-specific viewport if missing
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

  checkDependencies() {
    const issues = [];

    if (typeof jQuery === "undefined") {
      issues.push("jQuery not loaded");
    }

    if (typeof rishumit_ajax === "undefined") {
      issues.push("rishumit_ajax object not available");
    } else {
      this.log("Ajax config:", rishumit_ajax);
    }

    if (issues.length > 0) {
      this.error("Dependency issues:", issues);
      this.showError("שגיאה בטעינת התלויות הנדרשות: " + issues.join(", "));
    }
  }

  ensureViewport() {
    if (this.isMobile && !document.querySelector('meta[name="viewport"]')) {
      const viewport = document.createElement("meta");
      viewport.name = "viewport";
      viewport.content =
        "width=device-width, initial-scale=1.0, user-scalable=no, shrink-to-fit=no";
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
      this.log("AJAX Success intercepted:", {
        url: settings.url,
        hasResponseText: !!xhr.responseText,
        responseLength: xhr.responseText ? xhr.responseText.length : 0,
      });

      if (
        settings.url &&
        settings.url.includes("admin-ajax.php") &&
        xhr.responseText
      ) {
        try {
          const response = JSON.parse(xhr.responseText);
          this.log("Parsed AJAX response:", response);

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
            this.log("PAYMENT TRIGGER DETECTED:", paymentData);

            if (!this.paymentProcessed.has(paymentData.payment_id)) {
              this.paymentProcessed.add(paymentData.payment_id);

              // Add delay for mobile devices to ensure DOM is ready
              const delay = this.isMobile ? 500 : 100;
              setTimeout(() => {
                this.loadSDKAndProcess(paymentData.payment_id);
              }, delay);
            } else {
              this.log(
                "Payment already processed for ID:",
                paymentData.payment_id
              );
            }
          } else {
            this.log("No payment trigger found in response");
          }
        } catch (e) {
          this.log("Response is not JSON:", xhr.responseText.substring(0, 200));
        }
      }
    });

    // Also listen for AJAX errors
    jQuery(document).ajaxError((event, xhr, settings, thrownError) => {
      this.error("AJAX Error:", {
        url: settings.url,
        status: xhr.status,
        statusText: xhr.statusText,
        error: thrownError,
      });
    });

    this.log("Event listeners bound");
  }

  loadSDKAndProcess(paymentId) {
    this.log("loadSDKAndProcess called with ID:", paymentId);

    if (this.paymentInProgress) {
      this.log("Payment already in progress, skipping");
      return;
    }

    this.paymentInProgress = true;
    this.hasRetried = false;

    if (this.sdkLoaded && this.sdkInitialized) {
      this.log("SDK already loaded and initialized, processing payment");
      this.processPayment(paymentId);
      return;
    }

    if (this.sdkLoaded && !this.sdkInitialized) {
      this.log("SDK loaded but not initialized, configuring...");
      this.configureSDK(() => {
        this.processPayment(paymentId);
      });
      return;
    }

    this.log("Loading Meshulam SDK...");
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

      // Longer delay for mobile devices
      const delay = this.isMobile ? 1000 : 200;
      setTimeout(() => {
        this.configureSDK(() => {
          this.processPayment(paymentId);
        });
      }, delay);
    };

    script.onerror = (error) => {
      this.error("Failed to load Meshulam SDK:", error);
      this.showError("שגיאה בטעינת מערכת התשלומים - בעיית רשת או חסימה");
      this.paymentInProgress = false;
    };

    // Remove existing script if present
    const existingScript = document.querySelector('script[src*="meshulam"]');
    if (existingScript) {
      this.log("Removing existing Meshulam script");
      existingScript.remove();
    }

    const firstScript = document.getElementsByTagName("script")[0];
    firstScript.parentNode.insertBefore(script, firstScript);
  }

  configureSDK(callback) {
    this.log("Configuring Meshulam SDK...");

    const checkSDKAvailable = (retryCount = 0) => {
      if (typeof growPayment === "undefined") {
        this.error("growPayment object not available, retry:", retryCount);

        if (retryCount < this.maxRetries) {
          const retryDelay = this.isMobile ? 1500 : 800;
          setTimeout(() => {
            checkSDKAvailable(retryCount + 1);
          }, retryDelay);
          return;
        } else {
          this.showError("שגיאה בטעינת מערכת התשלומים - SDK לא זמין");
          this.paymentInProgress = false;
          return;
        }
      }

      this.log("growPayment available, initializing...");
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
          this.log("Payment successful:", response);
          this.handlePaymentSuccess(response);
        },
        onFailure: (response) => {
          this.error("Payment failed:", response);
          this.handlePaymentFailure(response);
        },
        onError: (response) => {
          this.error("Payment error:", response);
          this.handlePaymentError(response);
        },
        onTimeout: (response) => {
          this.error("Payment timeout:", response);
          this.handlePaymentTimeout(response);
        },
        onWalletChange: (state) => {
          this.log("Wallet state changed:", state);
          this.handleWalletChange(state);
        },
        onPaymentStart: (response) => {
          this.log("Payment started:", response);
        },
        onPaymentCancel: (response) => {
          this.log("Payment cancelled:", response);
          this.handlePaymentCancel(response);
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
        const errorMsg = this.isMobile
          ? "שגיאה בהגדרת מערכת התשלומים במכשיר נייד"
          : "שגיאה בהגדרת מערכת התשלומים";
        this.showError(errorMsg);
        this.paymentInProgress = false;
      }
    }
  }

  async processPayment(paymentId) {
    this.log("processPayment called with ID:", paymentId);
    this.showLoader();

    try {
      this.log("Creating payment process...");
      const response = await this.createPaymentProcess(paymentId);
      this.log("Payment process response:", response);

      // Enhanced response validation
      if (!response) {
        throw new Error("Empty response from server");
      }

      if (!response.success) {
        throw new Error(
          response.message || response.error || "Server returned failure"
        );
      }

      if (!response.authCode) {
        this.error("Missing authCode in response:", response);
        throw new Error("Missing authentication code from server");
      }

      // Validate authCode format
      if (
        typeof response.authCode !== "string" ||
        response.authCode.length < 10
      ) {
        this.error("Invalid authCode format:", response.authCode);
        throw new Error("Invalid authentication code format");
      }

      this.log(
        "Payment process created successfully, authCode:",
        response.authCode
      );
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
        throw new Error("SDK not properly initialized for payment rendering");
      }
    } catch (error) {
      this.error("Payment process error:", error);
      this.handleProcessError(error);
    }
  }

  async renderPaymentWithRetry(authCode, retryCount = 0) {
    try {
      this.log(
        `Attempting to render payment options (attempt ${retryCount + 1})`
      );
      growPayment.renderPaymentOptions(authCode);
      this.log("Payment options rendered successfully");
    } catch (error) {
      this.error(`Render attempt ${retryCount + 1} failed:`, error);

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
    this.log("Creating payment process for ID:", paymentId);

    // Validate input
    if (!paymentId) {
      throw new Error("Payment ID is required");
    }

    if (typeof rishumit_ajax === "undefined") {
      throw new Error("AJAX configuration not available");
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => {
      this.error("Request timeout after 30 seconds");
      controller.abort();
    }, 30000);

    try {
      const formData = new FormData();
      formData.append("action", "create_payment_process");
      formData.append("payment_id", paymentId);
      formData.append("nonce", rishumit_ajax.nonce);

      this.log("Sending request to:", rishumit_ajax.ajax_url);
      this.log("Request payload:", {
        action: "create_payment_process",
        payment_id: paymentId,
        nonce: rishumit_ajax.nonce ? "present" : "missing",
      });

      const response = await fetch(rishumit_ajax.ajax_url, {
        method: "POST",
        body: formData,
        signal: controller.signal,
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      clearTimeout(timeoutId);

      this.log("Response status:", response.status, response.statusText);

      if (!response.ok) {
        throw new Error(
          `HTTP error! status: ${response.status} - ${response.statusText}`
        );
      }

      const responseText = await response.text();
      this.log("Raw response:", responseText.substring(0, 500));

      let result;
      try {
        result = JSON.parse(responseText);
      } catch (parseError) {
        this.error("Failed to parse JSON response:", parseError);
        this.error("Response text:", responseText);
        throw new Error("Invalid JSON response from server");
      }

      this.log("Parsed response:", result);
      return result;
    } catch (error) {
      clearTimeout(timeoutId);
      if (error.name === "AbortError") {
        throw new Error("Request timeout - please try again");
      }
      throw error;
    }
  }

  handleRenderError(renderError, authCode) {
    const errorMsg = this.isMobile
      ? "שגיאה בהצגת אפשרויות התשלום במכשיר נייד"
      : "שגיאה בהצגת אפשרויות התשלום";

    if (!this.hasRetried) {
      this.log("Attempting to reinitialize SDK and retry...");
      this.hasRetried = true;
      this.sdkInitialized = false;

      const retryDelay = this.isMobile ? 2000 : 1000;
      setTimeout(() => {
        this.configureSDK(() => {
          try {
            growPayment.renderPaymentOptions(authCode);
          } catch (secondError) {
            this.error("Second attempt failed:", secondError);
            this.showError(errorMsg + " - נסה לרענן את הדף");
            this.resetPaymentState();
          }
        });
      }, retryDelay);
    } else {
      this.showError(errorMsg + " - נסה לרענן את הדף");
      this.resetPaymentState();
    }
  }

  handleProcessError(error) {
    let errorMessage = "שגיאה ביצירת תהליך התשלום";

    if (error.message.includes("Network") || error.message.includes("fetch")) {
      errorMessage = "בעיית רשת. אנא בדק את החיבור לאינטרנט";
    } else if (error.message.includes("timeout")) {
      errorMessage = "זמן הטעينה חרג. אנא נסה שוב";
    } else if (
      error.message.includes("authentication") ||
      error.message.includes("authCode")
    ) {
      errorMessage = "שגיאה באימות התשלום - הלינק לא תקין";
    } else if (error.message.includes("HTTP error! status: 4")) {
      errorMessage = "שגיאת הרשאה - אנא רענן את הדף ונסה שוב";
    } else if (error.message.includes("HTTP error! status: 5")) {
      errorMessage = "שגיאת שרת - אנא נסה שוב מאוחר יותר";
    } else if (this.isMobile && error.message.includes("SDK")) {
      errorMessage = "שגיאה במערכת התשלומים במכשיר נייד";
    }

    this.showError(errorMessage);
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

  // Event handlers
  handlePaymentSuccess(response) {
    this.resetPaymentState();
    this.log("Payment completed successfully:", response);

    const confirmationNumber = response.data?.confirmation_number || "";
    const paymentMethod = response.data?.payment_method || "";
    const strapiId = this.currentStrapiId || "";

    let redirectUrl = `/thank-you?confirmation=${confirmationNumber}&method=${paymentMethod}`;
    if (strapiId) {
      redirectUrl += `&id=${strapiId}`;
    }

    const redirectDelay = this.isMobile ? 1000 : 100;
    setTimeout(() => {
      window.location.href = redirectUrl;
    }, redirectDelay);
  }

  handlePaymentFailure(response) {
    this.resetPaymentState();
    const message = response.message || "שגיאה לא ידועה";

    // Specific handling for invalid link error
    if (
      message.includes("לינק") ||
      message.includes("תקין") ||
      message.includes("invalid")
    ) {
      this.showError("הלינק שנשלח אינו תקין - אנא רענן את הדף ונסה שוב");
    } else {
      this.showError("התשלום נכשל: " + message);
    }
  }

  handlePaymentError(response) {
    this.resetPaymentState();
    const message = response.message || "שגיאה טכנית";
    this.showError("שגיאה בתשלום: " + message);
  }

  handlePaymentTimeout(response) {
    this.resetPaymentState();
    this.showError("זמן התשלום פג. אנא נסה שוב.");
  }

  handleWalletChange(state) {
    this.log("Wallet state:", state);
    if (state === "open") {
      this.hideLoader();
    } else if (state === "close") {
      this.resetPaymentState();
    }
  }

  handlePaymentCancel(response) {
    this.resetPaymentState();
    this.log("Payment cancelled by user");
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
          ${this.isMobile ? "touch-action: none;" : ""}
        ">
          <div class="payment-spinner" style="
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            ${this.isMobile ? "width: 80%; max-width: 300px;" : ""}
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
            <p style="margin: 0; font-size: ${
              this.isMobile ? "16px" : "14px"
            };">מכין תשלום...</p>
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

  showError(message) {
    // Log error for debugging
    this.error("Showing error to user:", message);

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
        ${this.isMobile ? "padding: 20px; box-sizing: border-box;" : ""}
      ">
        <div style="
          background: white;
          padding: 20px;
          border-radius: 8px;
          text-align: center;
          ${
            this.isMobile
              ? "width: 100%; max-width: 400px; font-size: 16px;"
              : "max-width: 500px;"
          }
          box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        ">
          <p style="margin: 0 0 15px 0; color: #d32f2f; font-weight: bold;">${message}</p>
          <button onclick="this.closest('#payment-error').remove()" style="
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: ${this.isMobile ? "16px" : "14px"};
          ">סגור</button>
        </div>
      </div>
    `;
    document.body.appendChild(errorDiv);

    const autoRemoveTime = this.isMobile ? 15000 : 10000;
    setTimeout(() => {
      if (errorDiv.parentNode) {
        errorDiv.remove();
      }
    }, autoRemoveTime);
  }
}

// Enhanced initialization
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
