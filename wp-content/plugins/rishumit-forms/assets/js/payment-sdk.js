class RishumitPaymentSDK {
  constructor() {
    this.sdkLoaded = false;
    this.paymentInProgress = false;
    this.paymentProcessed = new Set(); // Track processed payment IDs
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

    // Only use the most reliable method - ajaxSuccess
    jQuery(document).ajaxSuccess((event, xhr, settings) => {
      if (
        settings.url &&
        settings.url.includes("admin-ajax.php") &&
        xhr.responseText
      ) {
        try {
          const response = JSON.parse(xhr.responseText);

          // Check for payment trigger in various response structures
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
            // Prevent duplicate processing
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

    if (this.sdkLoaded) {
      console.log("SDK already loaded, processing payment");
      this.processPayment(paymentId);
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
      this.configureSDK();
      this.processPayment(paymentId);
    };
    script.onerror = () => {
      console.error("Failed to load Meshulam SDK");
      this.showError("שגיאה בטעינת מערכת התשלומים");
    };

    const firstScript = document.getElementsByTagName("script")[0];
    firstScript.parentNode.insertBefore(script, firstScript);
  }

  configureSDK() {
    console.log("Configuring Meshulam SDK...");
    const config = {
      environment: "DEV",
      version: 1,
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

    if (typeof growPayment !== "undefined") {
      growPayment.init(config);
      console.log("Meshulam SDK configured successfully");
    } else {
      console.error("growPayment object not available");
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

        // Store the Strapi ID for later use in success handler
        this.currentStrapiId = response.strapiId || paymentId;

        if (typeof growPayment !== "undefined") {
          console.log("Calling growPayment.renderPaymentOptions");
          growPayment.renderPaymentOptions(response.authCode);
        } else {
          throw new Error("SDK not available");
        }
      } else {
        throw new Error(response.message || "Failed to create payment process");
      }
    } catch (error) {
      console.error("Payment process error:", error);
      this.showError("שגיאה ביצירת תהליך התשלום: " + error.message);
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

    // Get the Strapi ID from the current payment process
    // You'll need to store this when creating the payment
    const strapiId = this.currentStrapiId || "";

    // Build URL with all parameters
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
