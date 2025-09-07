class RishumitPaymentSDK {
  constructor() {
    this.sdkLoaded = false;
    this.sdkInitialized = false; // Track if SDK is fully initialized
    this.paymentInProgress = false;
    this.paymentProcessed = new Set();
    this.initializationRetries = 0;
    this.maxRetries = 3;
    this.init();
  }

  init() {
    console.log("RishumitPaymentSDK initializing...");
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

  bindFormEvents() {
    console.log("Binding form events...");

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
              this.loadSDKAndProcess(paymentData.payment_id);
            } else {
              console.log(
                "Payment already processed for ID:",
                paymentData.payment_id
              );
            }
          }
        } catch (e) {
          // Not JSON
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
    const script = document.createElement("script");
    script.type = "text/javascript";
    script.async = true;
    script.src = "https://cdn.meshulam.co.il/sdk/gs.min.js";

    script.onload = () => {
      console.log("Meshulam SDK loaded successfully");
      this.sdkLoaded = true;

      // Add a small delay to ensure the SDK is fully ready
      setTimeout(() => {
        this.configureSDK(() => {
          this.processPayment(paymentId);
        });
      }, 100);
    };

    script.onerror = () => {
      console.error("Failed to load Meshulam SDK");
      this.showError("שגיאה בטעינת מערכת התשלומים");
      this.paymentInProgress = false;
    };

    const firstScript = document.getElementsByTagName("script")[0];
    firstScript.parentNode.insertBefore(script, firstScript);
  }

  configureSDK(callback) {
    console.log("Configuring Meshulam SDK...");
    console.log("Mobile device detected:", this.isMobile);

    // Check if growPayment is available
    if (typeof growPayment === "undefined") {
      console.error("growPayment object not available");
      if (this.initializationRetries < this.maxRetries) {
        this.initializationRetries++;
        console.log(
          `Retrying SDK initialization (${this.initializationRetries}/${this.maxRetries})...`
        );
        setTimeout(
          () => {
            this.configureSDK(callback);
          },
          this.isMobile ? 1000 : 500
        );
        return;
      } else {
        this.showError("שגיאה בטעינת מערכת התשלומים - SDK לא זמין");
        this.paymentInProgress = false;
        return;
      }
    }

    const config = {
      environment: "PRODUCTION",
      version: 1,
      // Mobile-specific configuration
      mobile: this.isMobile
        ? {
            theme: "light",
            animation: false, // Disable animations on mobile for better performance
            fullscreen: true,
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
      const waitTime = this.isMobile ? 1000 : 200;
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
        setTimeout(
          () => {
            this.configureSDK(callback);
          },
          this.isMobile ? 2000 : 1000
        );
      } else {
        this.showError("שגיאה בהגדרת מערכת התשלומים - נסה לרענן את הדף");
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

        // Double-check that SDK is initialized before calling renderPaymentOptions
        if (typeof growPayment !== "undefined" && this.sdkInitialized) {
          console.log("Calling growPayment.renderPaymentOptions");

          try {
            // Mobile-specific: Add viewport meta tag if missing
            if (!document.querySelector('meta[name="viewport"]')) {
              const viewport = document.createElement("meta");
              viewport.name = "viewport";
              viewport.content =
                "width=device-width, initial-scale=1.0, user-scalable=no";
              document.head.appendChild(viewport);
            }

            growPayment.renderPaymentOptions(response.authCode);
          } catch (renderError) {
            console.error("Error rendering payment options:", renderError);

            // Enhanced error message for mobile
            const isMobile = /iPhone|iPad|iPod|Android/i.test(
              navigator.userAgent
            );
            const errorMsg = isMobile
              ? "שגיאה בהצגת אפשרויות התשלום במכשיר נייד. אנא נסה לרענן את הדף."
              : "שגיאה בהצגת אפשרויות התשלום";

            // Try to reinitialize and retry once
            if (!this.hasRetried) {
              console.log("Attempting to reinitialize SDK and retry...");
              this.hasRetried = true;
              this.sdkInitialized = false;

              // Longer delay for mobile retry
              setTimeout(
                () => {
                  this.configureSDK(() => {
                    try {
                      growPayment.renderPaymentOptions(response.authCode);
                    } catch (secondError) {
                      console.error("Second attempt failed:", secondError);
                      this.showError(errorMsg);
                      this.paymentInProgress = false;
                      this.hideLoader();
                    }
                  });
                },
                isMobile ? 1000 : 500
              );
            } else {
              this.showError(errorMsg);
              this.paymentInProgress = false;
              this.hideLoader();
            }
          }
        } else {
          throw new Error("SDK not properly initialized");
        }
      } else {
        throw new Error(response.message || "Failed to create payment process");
      }
    } catch (error) {
      console.error("Payment process error:", error);

      // More user-friendly error messages
      let errorMessage = "שגיאה ביצירת תהליך התשלום";
      if (error.message.includes("Network")) {
        errorMessage = "בעיית רשת. אנא בדק את החיבור לאינטרנט ונסה שוב";
      } else if (error.message.includes("timeout")) {
        errorMessage = "זמן הטעינה חרג. אנא נסה שוב";
      }

      this.showError(errorMessage + ": " + error.message);
      this.paymentInProgress = false;
      this.hideLoader();
    }
  }

  async createPaymentProcess(paymentId) {
    const formData = new FormData();
    formData.append("action", "create_payment_process");
    formData.append("payment_id", paymentId);
    formData.append("nonce", rishumit_ajax.nonce);

    const response = await fetch(rishumit_ajax.ajax_url, {
      method: "POST",
      body: formData,
    });

    if (!response.ok) {
      throw new Error("Network error");
    }

    return await response.json();
  }

  // Event handlers
  handlePaymentSuccess(response) {
    this.paymentInProgress = false;
    this.hideLoader();

    console.log("Payment completed successfully:", response);

    const confirmationNumber = response.data?.confirmation_number || "";
    const paymentMethod = response.data?.payment_method || "";
    const strapiId = this.currentStrapiId || "";

    let redirectUrl = `/thank-you?confirmation=${confirmationNumber}&method=${paymentMethod}`;
    if (strapiId) {
      redirectUrl += `&id=${strapiId}`;
    }

    window.location.href = redirectUrl;
  }

  handlePaymentFailure(response) {
    this.paymentInProgress = false;
    this.hideLoader();
    this.showError("התשלום נכשל: " + (response.message || "שגיאה לא ידועה"));
  }

  handlePaymentError(response) {
    this.paymentInProgress = false;
    this.hideLoader();
    this.showError("שגיאה בתשלום: " + (response.message || "שגיאה טכנית"));
  }

  handlePaymentTimeout(response) {
    this.paymentInProgress = false;
    this.hideLoader();
    this.showError("זמן התשלום פג. אנא נסה שוב.");
  }

  handleWalletChange(state) {
    if (state === "open") {
      this.hideLoader();
    } else if (state === "close") {
      this.paymentInProgress = false;
    }
  }

  handlePaymentCancel(response) {
    this.paymentInProgress = false;
    this.hideLoader();
  }

  // UI helpers
  showLoader() {
    let loader = document.getElementById("payment-loader");
    if (!loader) {
      loader = document.createElement("div");
      loader.id = "payment-loader";
      loader.innerHTML = `
                <div class="payment-overlay">
                    <div class="payment-spinner">
                        <div class="spinner"></div>
                        <p>מכין תשלום...</p>
                    </div>
                </div>
            `;
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
    errorDiv.className = "payment-error";
    errorDiv.innerHTML = `
            <div class="error-content">
                <p>${message}</p>
                <button onclick="this.parentElement.parentElement.remove()">סגור</button>
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
  window.rishumitPaymentSDK = new RishumitPaymentSDK();
});

if (
  document.readyState === "complete" ||
  document.readyState === "interactive"
) {
  window.rishumitPaymentSDK = new RishumitPaymentSDK();
}
