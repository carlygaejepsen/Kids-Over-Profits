/**
 * Admin Data Management - Main Controller
 * Handles the overall application state and coordination
 */

class AdminDataManager {
  constructor() {
    this.currentCategory = "companies";
    this.currentFacility = 0;
    this.facilities = [];
    this.projects = {
      companies: [],
      locations: [],
      referrers: [],
    };

    this.init();
  }

  init() {
    this.setupEventListeners();
    this.initializeNavigation();
    this.loadInitialData();
    this.setupFormHandlers();
  }

  setupEventListeners() {
    // Category navigation
    document.addEventListener("click", (e) => {
      if (e.target.classList.contains("category-tab")) {
        this.switchCategory(e.target.dataset.category);
      }
    });

    // Section toggles
    document.addEventListener("click", (e) => {
      if (e.target.classList.contains("section-toggle")) {
        this.toggleSection(e.target.closest(".section"));
      }
    });

    // Facility navigation
    document.addEventListener("click", (e) => {
      if (e.target.classList.contains("nav-btn")) {
        this.handleFacilityNavigation(e.target);
      }
    });

    // Form submissions
    document.addEventListener("submit", (e) => {
      if (e.target.classList.contains("facility-form")) {
        e.preventDefault();
        this.handleFormSubmission(e.target);
      }
    });
  }

  initializeNavigation() {
    // Initialize category navigation
    this.switchCategory("companies");

    // Initialize section toggles
    this.initializeSectionToggles();

    // Initialize facility navigation
    this.initializeFacilityNavigation();
  }

  switchCategory(category) {
    // Update active tab
    document.querySelectorAll(".category-tab").forEach((tab) => {
      tab.classList.remove("active");
    });
    document
      .querySelector(`[data-category="${category}"]`)
      .classList.add("active");

    // Show/hide content
    document.querySelectorAll(".category-content").forEach((content) => {
      content.classList.remove("active");
    });
    document.querySelector(`#${category}-content`).classList.add("active");

    this.currentCategory = category;
    this.loadCategoryData(category);
  }

  initializeSectionToggles() {
    document.querySelectorAll(".section").forEach((section) => {
      const toggle = section.querySelector(".section-toggle");
      if (toggle) {
        toggle.addEventListener("click", () => {
          this.toggleSection(section);
        });
      }
    });
  }

  toggleSection(section) {
    const isExpanded = section.classList.contains("expanded");

    if (isExpanded) {
      section.classList.remove("expanded");
    } else {
      section.classList.add("expanded");
    }
  }

  initializeFacilityNavigation() {
    // Add facility navigation handlers
    const addFacilityBtn = document.getElementById("add-facility-main-btn");
    if (addFacilityBtn) {
      addFacilityBtn.addEventListener("click", () => {
        this.addNewFacility();
      });
    }

    const sortFacilitiesBtn = document.getElementById("sort-facilities-btn");
    if (sortFacilitiesBtn) {
      sortFacilitiesBtn.addEventListener("click", () => {
        this.sortFacilities();
      });
    }
  }

  handleFacilityNavigation(button) {
    const action = button.dataset.action;

    switch (action) {
      case "prev":
        this.navigateToFacility(this.currentFacility - 1);
        break;
      case "next":
        this.navigateToFacility(this.currentFacility + 1);
        break;
      case "first":
        this.navigateToFacility(0);
        break;
      case "last":
        this.navigateToFacility(this.facilities.length - 1);
        break;
    }
  }

  navigateToFacility(index) {
    if (index >= 0 && index < this.facilities.length) {
      this.currentFacility = index;
      this.updateFacilityDisplay();
      this.loadFacilityData(this.facilities[index]);
    }
  }

  updateFacilityDisplay() {
    const counter = document.getElementById("facility-counter");
    const name = document.getElementById("current-facility-name");

    if (counter) {
      counter.textContent = `${this.currentFacility + 1} of ${
        this.facilities.length
      }`;
    }

    if (name && this.facilities[this.currentFacility]) {
      name.textContent =
        this.facilities[this.currentFacility].name || "Unnamed Facility";
    }
  }

  addNewFacility() {
    const newFacility = {
      id: Date.now(),
      name: "New Facility",
      data: {},
    };

    this.facilities.push(newFacility);
    this.currentFacility = this.facilities.length - 1;
    this.updateFacilityList();
    this.updateFacilityDisplay();
    this.loadFacilityData(newFacility);
  }

  sortFacilities() {
    this.facilities.sort((a, b) => {
      const nameA = (a.name || "").toLowerCase();
      const nameB = (b.name || "").toLowerCase();
      return nameA.localeCompare(nameB);
    });

    this.updateFacilityList();
    this.updateFacilityDisplay();
  }

  updateFacilityList() {
    const facilityList = document.getElementById("facility-list");
    if (!facilityList) return;

    facilityList.innerHTML = "";

    this.facilities.forEach((facility, index) => {
      const item = document.createElement("div");
      item.className = `facility-item ${
        index === this.currentFacility ? "active" : ""
      }`;
      item.innerHTML = `
                <span class="facility-name">${
                  facility.name || "Unnamed Facility"
                }</span>
                <div class="facility-actions">
                    <button class="facility-action" onclick="adminDataManager.navigateToFacility(${index})" title="Go to facility">
                        📍
                    </button>
                    <button class="facility-action" onclick="adminDataManager.deleteFacility(${index})" title="Delete facility">
                        🗑️
                    </button>
                </div>
            `;
      facilityList.appendChild(item);
    });
  }

  deleteFacility(index) {
    if (confirm("Are you sure you want to delete this facility?")) {
      this.facilities.splice(index, 1);

      if (this.currentFacility >= this.facilities.length) {
        this.currentFacility = Math.max(0, this.facilities.length - 1);
      }

      this.updateFacilityList();
      this.updateFacilityDisplay();

      if (this.facilities.length > 0) {
        this.loadFacilityData(this.facilities[this.currentFacility]);
      }
    }
  }

  loadFacilityData(facility) {
    // Load facility data into form
    if (facility && facility.data) {
      Object.keys(facility.data).forEach((key) => {
        const input = document.querySelector(`[data-field="${key}"]`);
        if (input) {
          input.value = facility.data[key] || "";
        }
      });
    }
  }

  handleFormSubmission(form) {
    const formData = new FormData(form);
    const data = {};

    // Collect form data
    form.querySelectorAll("[data-field]").forEach((input) => {
      const field = input.dataset.field;
      data[field] = input.value;
    });

    // Update current facility data
    if (this.facilities[this.currentFacility]) {
      this.facilities[this.currentFacility].data = {
        ...this.facilities[this.currentFacility].data,
        ...data,
      };
      this.facilities[this.currentFacility].name =
        data["identification.facilityName"] || "Unnamed Facility";
    }

    this.updateFacilityList();
    this.showNotification("Facility data saved successfully!", "success");
  }

  loadCategoryData(category) {
    // Load data specific to the current category
    switch (category) {
      case "companies":
        this.loadCompanyProjects();
        break;
      case "locations":
        this.loadLocationProjects();
        break;
      case "referrers":
        this.loadReferrerProjects();
        break;
    }
  }

  loadCompanyProjects() {
    // Load company projects
    console.log("Loading company projects...");
  }

  loadLocationProjects() {
    // Load location projects
    console.log("Loading location projects...");
  }

  loadReferrerProjects() {
    // Load referrer projects
    console.log("Loading referrer projects...");
  }

  loadInitialData() {
    // Load initial data from API or local storage
    this.facilities = [
      {
        id: 1,
        name: "Sample Facility",
        data: {},
      },
    ];

    this.updateFacilityList();
    this.updateFacilityDisplay();
  }

  setupFormHandlers() {
    // Setup form-specific handlers
    this.setupAutocompleteHandlers();
    this.setupValidationHandlers();
  }

  setupAutocompleteHandlers() {
    // Setup autocomplete for various fields
    const autocompleteFields = document.querySelectorAll("[data-autocomplete]");
    autocompleteFields.forEach((field) => {
      this.attachAutocomplete(field);
    });
  }

  attachAutocomplete(input) {
    // Attach autocomplete functionality to input
    const type = input.dataset.autocomplete;
    // Implementation would go here
  }

  setupValidationHandlers() {
    // Setup form validation
    const form = document.querySelector(".facility-form");
    if (form) {
      form.addEventListener("input", (e) => {
        this.validateField(e.target);
      });
    }
  }

  validateField(field) {
    // Validate individual field
    const value = field.value.trim();
    const required = field.hasAttribute("required");

    if (required && !value) {
      field.classList.add("error");
      return false;
    } else {
      field.classList.remove("error");
      return true;
    }
  }

  showNotification(message, type = "info") {
    // Show notification to user
    const notification = document.createElement("div");
    notification.className = `notification notification-${type}`;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
      notification.remove();
    }, 3000);
  }
}

// Initialize the application
let adminDataManager;

document.addEventListener("DOMContentLoaded", () => {
  adminDataManager = new AdminDataManager();
});

// Export for global access
window.AdminDataManager = AdminDataManager;
window.adminDataManager = adminDataManager;
