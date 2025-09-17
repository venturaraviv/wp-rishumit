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

    console.log("Mobile detection:", {
      isMobile,
      isTouchDevice,
      isSmallScreen,
      userAgent: navigator.userAgent,
    });
    return isMobile || (isTouchDevice && isSmallScreen);
  }

  init() {
    console.log("RishumitPaymentSDK initializing...");
    console.log("Mobile device detected:", this.isMobile);

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

  ensureViewport() {
    if (this.isMobile && !document.querySelector('meta[name="viewport"]')) {
      const viewport = document.createElement("meta");
      viewport.name = "viewport";
      viewport.content =
        "width=device-width, initial-scale=1.0, user-scalable=no, shrink-to-fit=no";
      document.head.appendChild(viewport);
      console.log("Viewport meta tag added for mobile");
    }
  }

  bindFormEvents() {
    console.log("Binding form events...");

    // Ensure jQuery is available
    if (typeof jQuery === "undefined") {
      console.error("jQuery not loaded - payment detection may not work");
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
              console.log("PAYMENT TRIGGER DETECTED:", paymentData);
              this.paymentProcessed.add(paymentData.payment_id);

              // Add delay for mobile devices to ensure DOM is ready
              const delay = this.isMobile ? 500 : 100;
              setTimeout(() => {
                this.loadSDKAndProcess(paymentData.payment_id);
              }, delay);
            } else {
              console.log(
                "Payment already processed for ID:",
                paymentData.payment_id
              );
            }
          }
        } catch (e) {
          console.error("Error parsing AJAX response:", e);
        }
      }
    });

    console.log("Event listeners bound");
  }

  loadSDKAndProcess(paymentId) {
    console.log("loadSDKAndProcess called with ID:", paymentId);

    if (this.paymentInProgress) {
      console.log("Payment already in progress, skipping");
      return;
    }

    this.paymentInProgress = true;
    this.hasRetried = false; // Reset retry flag

    if (this.sdkLoaded && this.sdkInitialized) {
      console.log("SDK already loaded and initialized, processing payment");
      this.processPayment(paymentId);
      return;
    }

    if (this.sdkLoaded && !this.sdkInitialized) {
      console.log("SDK loaded but not initialized, configuring...");
      this.configureSDK(() => {
        this.processPayment(paymentId);
      });
      return;
    }

    console.log("Loading Meshulam SDK...");
    this.loadMeshulamSDK(paymentId);
  }

  loadMeshulamSDK(paymentId) {
    const script = document.createElement("script");
    script.type = "text/javascript";
    script.async = true;
    script.src = "https://cdn.meshulam.co.il/sdk/gs.min.js";

    script.onload = () => {
      console.log("Meshulam SDK loaded successfully");
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
      console.error("Failed to load Meshulam SDK:", error);
      this.showError("שגיאה בטעינת מערכת התשלומים - בעיית רשת");
      this.paymentInProgress = false;
    };

    // Remove existing script if present
    const existingScript = document.querySelector('script[src*="meshulam"]');
    if (existingScript) {
      existingScript.remove();
    }

    const firstScript = document.getElementsByTagName("script")[0];
    firstScript.parentNode.insertBefore(script, firstScript);
  }

  configureSDK(callback) {
    console.log("Configuring Meshulam SDK...");
    console.log("Mobile device detected:", this.isMobile);

    // Wait for SDK to be fully available
    const checkSDKAvailable = (retryCount = 0) => {
      if (typeof growPayment === "undefined") {
        console.error("growPayment object not available");

        if (retryCount < this.maxRetries) {
          console.log(
            `Retrying SDK availability check (${retryCount + 1}/${
              this.maxRetries
            })...`
          );
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

      // SDK is available, proceed with configuration
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
          console.log("Payment successful:", response);
          this.handlePaymentSuccess(response);
        },
        onFailure: (response) => {
          console.log("Payment failed:", response);
          this.handlePaymentFailure(response);
        },
        onError: (response) => {
          console.log("Payment error:", response);
          this.handlePaymentError(response);
        },
        onTimeout: (response) => {
          console.log("Payment timeout:", response);
          this.handlePaymentTimeout(response);
        },
        onWalletChange: (state) => {
          console.log("Wallet state changed:", state);
          this.handleWalletChange(state);
        },
        onPaymentStart: (response) => {
          console.log("Payment started:", response);
        },
        onPaymentCancel: (response) => {
          console.log("Payment cancelled:", response);
          this.handlePaymentCancel(response);
        },
      },
    };

    try {
      console.log("Initializing with config:", config);
      growPayment.init(config);
      console.log("Meshulam SDK configured successfully");
      this.sdkInitialized = true;
      this.initializationRetries = 0;

      // Longer wait for mobile devices
      const waitTime = this.isMobile ? 1500 : 300;
      setTimeout(() => {
        if (callback) callback();
      }, waitTime);
    } catch (error) {
      console.error("Error configuring SDK:", error);

      if (this.initializationRetries < this.maxRetries) {
        this.initializationRetries++;
        console.log(
          `Retrying SDK configuration (${this.initializationRetries}/${this.maxRetries})...`
        );
        const retryDelay = this.isMobile ? 2000 : 1000;
        setTimeout(() => {
          this.configureSDK(callback);
        }, retryDelay);
      } else {
        const errorMsg = this.isMobile
          ? "שגיאה בהגדרת מערכת התשלומים במכשיר נייד. אנא נסה לרענן את הדף או השתמש בדפדפן אחר"
          : "שגיאה בהגדרת מערכת התשלומים - נסה לרענן את הדף";
        this.showError(errorMsg);
        this.paymentInProgress = false;
      }
    }
  }

  async processPayment(paymentId) {
    console.log("processPayment called with ID:", paymentId);
    this.showLoader();

    try {
      console.log("Creating payment process...");
      const response = await this.createPaymentProcess(paymentId);
      console.log("Payment process response:", response);

      if (response.success && response.authCode) {
        console.log("Payment process created, authCode:", response.authCode);
        this.currentStrapiId = response.strapiId || paymentId;

        // Ensure SDK is still initialized before rendering
        if (typeof growPayment !== "undefined" && this.sdkInitialized) {
          console.log("Calling growPayment.renderPaymentOptions");

          try {
            // For mobile, ensure proper styling and prevent zoom
            if (this.isMobile) {
              document.body.style.overflow = "hidden";
              document.documentElement.style.overflow = "hidden";
            }

            await this.renderPaymentWithRetry(response.authCode);
          } catch (renderError) {
            console.error("Error rendering payment options:", renderError);
            this.handleRenderError(renderError, response.authCode);
          }
        } else {
          throw new Error("SDK not properly initialized for payment rendering");
        }
      } else {
        throw new Error(response.message || "Failed to create payment process");
      }
    } catch (error) {
      console.error("Payment process error:", error);
      this.handleProcessError(error);
    }
  }

  async renderPaymentWithRetry(authCode, retryCount = 0) {
    try {
      growPayment.renderPaymentOptions(authCode);
    } catch (error) {
      if (retryCount < 2) {
        console.log(`Retrying payment render (${retryCount + 1}/2)...`);
        const delay = this.isMobile ? 1000 : 500;
        await new Promise((resolve) => setTimeout(resolve, delay));
        return this.renderPaymentWithRetry(authCode, retryCount + 1);
      } else {
        throw error;
      }
    }
  }

  handleRenderError(renderError, authCode) {
    const isMobile = this.isMobile;
    const errorMsg = isMobile
      ? "שגיאה בהצגת אפשרויות התשלום במכשיר נייד. אנא נסה לרענן את הדף או השתמש בדפדפן אחר."
      : "שגיאה בהצגת אפשרויות התשלום";

    if (!this.hasRetried) {
      console.log("Attempting to reinitialize SDK and retry...");
      this.hasRetried = true;
      this.sdkInitialized = false;

      const retryDelay = isMobile ? 2000 : 1000;
      setTimeout(() => {
        this.configureSDK(() => {
          try {
            growPayment.renderPaymentOptions(authCode);
          } catch (secondError) {
            console.error("Second attempt failed:", secondError);
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
      errorMessage = "בעיית רשת. אנא בדק את החיבור לאינטרנט ונסה שוב";
    } else if (error.message.includes("timeout")) {
      errorMessage = "זמן הטעינה חרג. אנא נסה שוב";
    } else if (this.isMobile && error.message.includes("SDK")) {
      errorMessage = "שגיאה במערכת התשלומים במכשיר נייד. אנא נסה דפדפן אחר";
    }

    this.showError(errorMessage + ": " + error.message);
    this.resetPaymentState();
  }

  resetPaymentState() {
    this.paymentInProgress = false;
    this.hideLoader();

    // Reset mobile styles
    if (this.isMobile) {
      document.body.style.overflow = "";
      document.documentElement.style.overflow = "";
    }
  }

  async createPaymentProcess(paymentId) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

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
      return result;
    } catch (error) {
      clearTimeout(timeoutId);
      if (error.name === "AbortError") {
        throw new Error("Request timeout - please try again");
      }
      throw error;
    }
  }

  // Event handlers
  handlePaymentSuccess(response) {
    this.resetPaymentState();
    console.log("Payment completed successfully:", response);

    const confirmationNumber = response.data?.confirmation_number || "";
    const paymentMethod = response.data?.payment_method || "";
    const strapiId = this.currentStrapiId || "";

    let redirectUrl = `/thank-you?confirmation=${confirmationNumber}&method=${paymentMethod}`;
    if (strapiId) {
      redirectUrl += `&id=${strapiId}`;
    }

    // Add delay for mobile to ensure payment completion
    const redirectDelay = this.isMobile ? 1000 : 100;
    setTimeout(() => {
      window.location.href = redirectUrl;
    }, redirectDelay);
  }

  handlePaymentFailure(response) {
    this.resetPaymentState();
    const message = response.message || "שגיאה לא ידועה";
    this.showError("התשלום נכשל: " + message);
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
    console.log("Wallet state:", state);
    if (state === "open") {
      this.hideLoader();
    } else if (state === "close") {
      this.resetPaymentState();
    }
  }

  handlePaymentCancel(response) {
    this.resetPaymentState();
    console.log("Payment cancelled by user");
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

      // Add CSS animation
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

    // Auto-remove after 15 seconds (longer for mobile)
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

// Fallback initialization
if (
  document.readyState === "complete" ||
  document.readyState === "interactive"
) {
  if (!window.rishumitPaymentSDK) {
    window.rishumitPaymentSDK = new RishumitPaymentSDK();
  }
}
