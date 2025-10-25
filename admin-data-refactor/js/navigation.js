/**
 * Navigation Component
 * Handles category navigation, section toggles, and facility navigation
 */

class NavigationManager {
  constructor() {
    this.currentCategory = "companies";
    this.expandedSections = new Set();
    this.init();
  }

  init() {
    this.setupCategoryNavigation();
    this.setupSectionToggles();
    this.setupFacilityNavigation();
  }

  setupCategoryNavigation() {
    const categoryTabs = document.querySelectorAll(".category-tab");

    categoryTabs.forEach((tab) => {
      tab.addEventListener("click", (e) => {
        e.preventDefault();
        this.switchCategory(tab.dataset.category);
      });
    });
  }

  switchCategory(category) {
    // Update active tab
    document.querySelectorAll(".category-tab").forEach((tab) => {
      tab.classList.remove("active");
    });

    const activeTab = document.querySelector(`[data-category="${category}"]`);
    if (activeTab) {
      activeTab.classList.add("active");
    }

    // Show/hide content
    document.querySelectorAll(".category-content").forEach((content) => {
      content.classList.remove("active");
    });

    const activeContent = document.querySelector(`#${category}-content`);
    if (activeContent) {
      activeContent.classList.add("active");
    }

    this.currentCategory = category;

    // Dispatch custom event for other components
    document.dispatchEvent(
      new CustomEvent("categoryChanged", {
        detail: { category },
      })
    );
  }

  setupSectionToggles() {
    const sectionToggles = document.querySelectorAll(".section-toggle");

    sectionToggles.forEach((toggle) => {
      toggle.addEventListener("click", (e) => {
        e.preventDefault();
        const section = toggle.closest(".section");
        this.toggleSection(section);
      });
    });
  }

  toggleSection(section) {
    const sectionId = section.id;
    const isExpanded = section.classList.contains("expanded");

    if (isExpanded) {
      section.classList.remove("expanded");
      this.expandedSections.delete(sectionId);
    } else {
      section.classList.add("expanded");
      this.expandedSections.add(sectionId);
    }

    // Update toggle icon
    const toggle = section.querySelector(".section-toggle");
    if (toggle) {
      toggle.textContent = isExpanded ? "▶" : "▼";
    }

    // Dispatch custom event
    document.dispatchEvent(
      new CustomEvent("sectionToggled", {
        detail: {
          sectionId,
          isExpanded: !isExpanded,
        },
      })
    );
  }

  setupFacilityNavigation() {
    // Facility navigation buttons
    const navButtons = document.querySelectorAll(".nav-btn");

    navButtons.forEach((button) => {
      button.addEventListener("click", (e) => {
        e.preventDefault();
        this.handleFacilityNavigation(button);
      });
    });

    // Facility list items
    const facilityItems = document.querySelectorAll(".facility-item");

    facilityItems.forEach((item, index) => {
      item.addEventListener("click", (e) => {
        e.preventDefault();
        this.selectFacility(index);
      });
    });
  }

  handleFacilityNavigation(button) {
    const action = button.dataset.action;

    switch (action) {
      case "prev":
        this.navigateToFacility(-1);
        break;
      case "next":
        this.navigateToFacility(1);
        break;
      case "first":
        this.navigateToFacility("first");
        break;
      case "last":
        this.navigateToFacility("last");
        break;
    }
  }

  navigateToFacility(direction) {
    // This would integrate with the main facility manager
    document.dispatchEvent(
      new CustomEvent("facilityNavigation", {
        detail: { direction },
      })
    );
  }

  selectFacility(index) {
    // Update active facility in list
    document.querySelectorAll(".facility-item").forEach((item) => {
      item.classList.remove("active");
    });

    const selectedItem = document.querySelectorAll(".facility-item")[index];
    if (selectedItem) {
      selectedItem.classList.add("active");
    }

    // Dispatch custom event
    document.dispatchEvent(
      new CustomEvent("facilitySelected", {
        detail: { index },
      })
    );
  }

  // TOC (Table of Contents) functionality
  setupTOC() {
    const tocToggle = document.getElementById("toc-toggle-btn");
    const tocContent = document.querySelector(".toc-content");

    if (tocToggle && tocContent) {
      tocToggle.addEventListener("click", () => {
        this.toggleTOC(tocContent);
      });
    }
  }

  toggleTOC(tocContent) {
    const isVisible = tocContent.style.display !== "none";
    tocContent.style.display = isVisible ? "none" : "block";

    const toggle = document.getElementById("toc-toggle-btn");
    if (toggle) {
      toggle.textContent = isVisible ? "🔍" : "🔎";
    }
  }

  // Section navigation
  setupSectionNavigation() {
    const sectionButtons = document.querySelectorAll(".section-btn");

    sectionButtons.forEach((button) => {
      button.addEventListener("click", (e) => {
        e.preventDefault();
        this.navigateToSection(button.dataset.section);
      });
    });
  }

  navigateToSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (section) {
      section.scrollIntoView({ behavior: "smooth" });

      // Expand section if collapsed
      if (!section.classList.contains("expanded")) {
        this.toggleSection(section);
      }
    }
  }

  // Utility methods
  expandAllSections() {
    document.querySelectorAll(".section").forEach((section) => {
      if (!section.classList.contains("expanded")) {
        this.toggleSection(section);
      }
    });
  }

  collapseAllSections() {
    document.querySelectorAll(".section").forEach((section) => {
      if (section.classList.contains("expanded")) {
        this.toggleSection(section);
      }
    });
  }

  // State management
  saveState() {
    const state = {
      currentCategory: this.currentCategory,
      expandedSections: Array.from(this.expandedSections),
    };

    localStorage.setItem("adminDataNavigationState", JSON.stringify(state));
  }

  loadState() {
    const savedState = localStorage.getItem("adminDataNavigationState");
    if (savedState) {
      const state = JSON.parse(savedState);
      this.currentCategory = state.currentCategory || "companies";
      this.expandedSections = new Set(state.expandedSections || []);

      // Restore UI state
      this.switchCategory(this.currentCategory);
      this.expandedSections.forEach((sectionId) => {
        const section = document.getElementById(sectionId);
        if (section) {
          this.toggleSection(section);
        }
      });
    }
  }
}

// Initialize navigation manager
let navigationManager;

document.addEventListener("DOMContentLoaded", () => {
  navigationManager = new NavigationManager();
  navigationManager.loadState();
});

// Export for global access
window.NavigationManager = NavigationManager;
window.navigationManager = navigationManager;
