/**
 * Form Manager Component
 * Handles form validation, data collection, and submission
 */

class FormManager {
  constructor() {
    this.formData = {};
    this.validationRules = {};
    this.autocompleteData = {};
    this.init();
  }

  init() {
    this.setupFormHandlers();
    this.setupValidation();
    this.setupAutocomplete();
    this.loadInitialData();
  }

  setupFormHandlers() {
    // Form submission
    const forms = document.querySelectorAll(".facility-form");
    forms.forEach((form) => {
      form.addEventListener("submit", (e) => {
        e.preventDefault();
        this.handleFormSubmission(form);
      });
    });

    // Real-time data collection
    document.addEventListener("input", (e) => {
      if (e.target.hasAttribute("data-field")) {
        this.updateFormData(e.target);
      }
    });

    // Field changes
    document.addEventListener("change", (e) => {
      if (e.target.hasAttribute("data-field")) {
        this.handleFieldChange(e.target);
      }
    });
  }

  setupValidation() {
    this.validationRules = {
      "identification.facilityName": {
        required: true,
        minLength: 2,
        message: "Facility name is required and must be at least 2 characters",
      },
      "identification.currentOperator": {
        required: true,
        message: "Current operator is required",
      },
      "location.address": {
        required: true,
        message: "Address is required",
      },
      "location.city": {
        required: true,
        message: "City is required",
      },
      "location.state": {
        required: true,
        message: "State is required",
      },
      "location.zipCode": {
        required: true,
        pattern: /^\d{5}(-\d{4})?$/,
        message: "ZIP code must be in format 12345 or 12345-6789",
      },
    };

    // Setup validation on blur
    document.addEventListener(
      "blur",
      (e) => {
        if (e.target.hasAttribute("data-field")) {
          this.validateField(e.target);
        }
      },
      true
    );
  }

  setupAutocomplete() {
    // Setup autocomplete for various field types
    this.setupOperatorAutocomplete();
    this.setupStateAutocomplete();
    this.setupReferrerAutocomplete();
  }

  setupOperatorAutocomplete() {
    const operatorFields = document.querySelectorAll(
      '[data-autocomplete="operator"]'
    );
    operatorFields.forEach((field) => {
      this.attachAutocomplete(field, "operator");
    });
  }

  setupStateAutocomplete() {
    const stateFields = document.querySelectorAll(
      '[data-autocomplete="state"]'
    );
    stateFields.forEach((field) => {
      this.attachAutocomplete(field, "state");
    });
  }

  setupReferrerAutocomplete() {
    const referrerFields = document.querySelectorAll(
      '[data-autocomplete="referrer"]'
    );
    referrerFields.forEach((field) => {
      this.attachAutocomplete(field, "referrer");
    });
  }

  attachAutocomplete(input, type) {
    let timeout;

    input.addEventListener("input", (e) => {
      clearTimeout(timeout);
      const query = e.target.value.trim();

      if (query.length < 2) {
        this.hideAutocomplete(input);
        return;
      }

      timeout = setTimeout(() => {
        this.showAutocomplete(input, query, type);
      }, 300);
    });

    input.addEventListener("blur", () => {
      setTimeout(() => this.hideAutocomplete(input), 200);
    });
  }

  async showAutocomplete(input, query, type) {
    try {
      const suggestions = await this.fetchAutocompleteData(query, type);
      this.displayAutocomplete(input, suggestions);
    } catch (error) {
      console.error("Autocomplete error:", error);
    }
  }

  async fetchAutocompleteData(query, type) {
    // This would make API calls to get autocomplete data
    // For now, return mock data
    const mockData = {
      operator: ["Acme Corporation", "Beta Industries", "Gamma Solutions"],
      state: ["California", "Texas", "Florida", "New York"],
      referrer: ["John Smith", "Jane Doe", "Educational Consultants Inc"],
    };

    const data = mockData[type] || [];
    return data.filter((item) =>
      item.toLowerCase().includes(query.toLowerCase())
    );
  }

  displayAutocomplete(input, suggestions) {
    this.hideAutocomplete(input);

    if (suggestions.length === 0) return;

    const dropdown = document.createElement("div");
    dropdown.className = "autocomplete-dropdown";
    dropdown.style.display = "block";

    suggestions.forEach((suggestion) => {
      const item = document.createElement("div");
      item.className = "autocomplete-item";
      item.textContent = suggestion;
      item.addEventListener("click", () => {
        input.value = suggestion;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        this.hideAutocomplete(input);
      });
      dropdown.appendChild(item);
    });

    input.parentNode.appendChild(dropdown);
  }

  hideAutocomplete(input) {
    const dropdown = input.parentNode.querySelector(".autocomplete-dropdown");
    if (dropdown) {
      dropdown.remove();
    }
  }

  updateFormData(field) {
    const fieldName = field.dataset.field;
    const value = field.value;

    this.formData[fieldName] = value;

    // Dispatch custom event
    document.dispatchEvent(
      new CustomEvent("formDataUpdated", {
        detail: { fieldName, value },
      })
    );
  }

  handleFieldChange(field) {
    const fieldName = field.dataset.field;
    const value = field.value;

    // Handle special field types
    if (fieldName === "identification.currentOperator") {
      this.handleOperatorChange(value);
    } else if (fieldName === "location.state") {
      this.handleStateChange(value);
    }
  }

  handleOperatorChange(operator) {
    // Update operator-related fields
    const operatorFields = document.querySelectorAll(
      '[data-field*="operator"]'
    );
    operatorFields.forEach((field) => {
      if (field.dataset.field !== "identification.currentOperator") {
        // Update related fields based on operator selection
      }
    });
  }

  handleStateChange(state) {
    // Update state-related fields
    const stateFields = document.querySelectorAll('[data-field*="state"]');
    stateFields.forEach((field) => {
      if (field.dataset.field !== "location.state") {
        // Update related fields based on state selection
      }
    });
  }

  validateField(field) {
    const fieldName = field.dataset.field;
    const rules = this.validationRules[fieldName];

    if (!rules) return true;

    const value = field.value.trim();
    let isValid = true;
    let errorMessage = "";

    // Required validation
    if (rules.required && !value) {
      isValid = false;
      errorMessage = rules.message;
    }

    // Min length validation
    if (isValid && rules.minLength && value.length < rules.minLength) {
      isValid = false;
      errorMessage = rules.message;
    }

    // Pattern validation
    if (isValid && rules.pattern && !rules.pattern.test(value)) {
      isValid = false;
      errorMessage = rules.message;
    }

    // Update field appearance
    this.updateFieldValidation(field, isValid, errorMessage);

    return isValid;
  }

  updateFieldValidation(field, isValid, errorMessage) {
    const errorElement = field.parentNode.querySelector(".field-error");

    if (isValid) {
      field.classList.remove("error");
      if (errorElement) {
        errorElement.remove();
      }
    } else {
      field.classList.add("error");

      if (!errorElement) {
        const error = document.createElement("div");
        error.className = "field-error";
        error.textContent = errorMessage;
        field.parentNode.appendChild(error);
      } else {
        errorElement.textContent = errorMessage;
      }
    }
  }

  validateForm(form) {
    const fields = form.querySelectorAll("[data-field]");
    let isValid = true;

    fields.forEach((field) => {
      if (!this.validateField(field)) {
        isValid = false;
      }
    });

    return isValid;
  }

  handleFormSubmission(form) {
    if (!this.validateForm(form)) {
      this.showNotification("Please fix the errors in the form", "error");
      return;
    }

    const formData = this.collectFormData(form);

    // Dispatch custom event for form submission
    document.dispatchEvent(
      new CustomEvent("formSubmitted", {
        detail: { formData },
      })
    );

    this.showNotification("Form submitted successfully!", "success");
  }

  collectFormData(form) {
    const data = {};
    const fields = form.querySelectorAll("[data-field]");

    fields.forEach((field) => {
      const fieldName = field.dataset.field;
      const value = field.value;
      data[fieldName] = value;
    });

    return data;
  }

  loadFormData(data) {
    Object.keys(data).forEach((fieldName) => {
      const field = document.querySelector(`[data-field="${fieldName}"]`);
      if (field) {
        field.value = data[fieldName] || "";
        field.dispatchEvent(new Event("input", { bubbles: true }));
      }
    });
  }

  clearForm(form) {
    const fields = form.querySelectorAll("[data-field]");
    fields.forEach((field) => {
      field.value = "";
      field.classList.remove("error");
    });

    // Clear error messages
    const errorElements = form.querySelectorAll(".field-error");
    errorElements.forEach((error) => error.remove());
  }

  showNotification(message, type = "info") {
    const notification = document.createElement("div");
    notification.className = `notification notification-${type}`;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
      notification.remove();
    }, 3000);
  }

  loadInitialData() {
    // Load any saved form data
    const savedData = localStorage.getItem("adminDataFormData");
    if (savedData) {
      try {
        const data = JSON.parse(savedData);
        this.loadFormData(data);
      } catch (error) {
        console.error("Error loading saved form data:", error);
      }
    }
  }

  saveFormData() {
    localStorage.setItem("adminDataFormData", JSON.stringify(this.formData));
  }
}

// Initialize form manager
let formManager;

document.addEventListener("DOMContentLoaded", () => {
  formManager = new FormManager();
});

// Export for global access
window.FormManager = FormManager;
window.formManager = formManager;
