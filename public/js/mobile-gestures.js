/**
 * Mobile Gestures JavaScript
 * Phase 6.5: Swipe gestures and touch interactions
 */

class MobileGestures {
    constructor() {
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.touchEndX = 0;
        this.touchEndY = 0;
        this.currentSwipedElement = null;
        this.swipeThreshold = 50; // Minimum distance for swipe
        this.init();
    }

    init() {
        this.setupSwipeableElements();
        this.setupPullToRefresh();
        this.setupMobileNavigation();
    }

    setupSwipeableElements() {
        const swipeableElements = document.querySelectorAll('.swipeable');

        swipeableElements.forEach(element => {
            this.addSwipeListeners(element);
        });
    }

    addSwipeListeners(element) {
        let startX = 0;
        let currentX = 0;
        let isDragging = false;

        element.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            currentX = startX;
            isDragging = true;
            element.classList.add('swiping');
        }, { passive: true });

        element.addEventListener('touchmove', (e) => {
            if (!isDragging) return;

            currentX = e.touches[0].clientX;
            const diff = currentX - startX;

            // Only allow horizontal swipe, prevent if vertical scroll
            const diffY = Math.abs(e.touches[0].clientY - e.touches[0].clientY);
            if (diffY > Math.abs(diff)) {
                isDragging = false;
                return;
            }

            // Apply transform to show swipe
            if (Math.abs(diff) > 10) {
                e.preventDefault();
                element.style.transform = `translateX(${diff}px)`;
            }
        }, { passive: false });

        element.addEventListener('touchend', (e) => {
            if (!isDragging) return;

            const diff = currentX - startX;
            isDragging = false;
            element.classList.remove('swiping');

            // Swipe left (show right actions)
            if (diff < -this.swipeThreshold) {
                this.handleSwipeLeft(element);
            }
            // Swipe right (show left actions or clear)
            else if (diff > this.swipeThreshold) {
                this.handleSwipeRight(element);
            }
            // Snap back
            else {
                this.resetSwipe(element);
            }
        }, { passive: true });

        element.addEventListener('touchcancel', () => {
            isDragging = false;
            element.classList.remove('swiping');
            this.resetSwipe(element);
        }, { passive: true });
    }

    handleSwipeLeft(element) {
        // Show quick actions (edit, delete)
        this.closeCurrentSwipe();
        this.currentSwipedElement = element;

        const actionsWidth = 160; // Width of action buttons
        element.style.transform = `translateX(-${actionsWidth}px)`;
        element.style.transition = 'transform 0.3s ease';

        // Add click outside listener to close
        setTimeout(() => {
            document.addEventListener('click', this.closeSwipeOnClickOutside.bind(this), { once: true });
        }, 100);
    }

    handleSwipeRight(element) {
        // For transactions: mark as cleared/uncleared
        // For other elements: dismiss or close

        if (element.classList.contains('transaction-row')) {
            this.toggleTransactionCleared(element);
        }

        this.resetSwipe(element);
    }

    resetSwipe(element) {
        element.style.transform = 'translateX(0)';
        element.style.transition = 'transform 0.3s ease';

        setTimeout(() => {
            element.style.transition = '';
        }, 300);

        if (this.currentSwipedElement === element) {
            this.currentSwipedElement = null;
        }
    }

    closeCurrentSwipe() {
        if (this.currentSwipedElement) {
            this.resetSwipe(this.currentSwipedElement);
        }
    }

    closeSwipeOnClickOutside(e) {
        if (this.currentSwipedElement && !this.currentSwipedElement.contains(e.target)) {
            this.closeCurrentSwipe();
        }
    }

    toggleTransactionCleared(element) {
        // Placeholder for transaction cleared toggle
        // This would integrate with your transaction API
        console.log('Toggle cleared status for transaction');

        // Visual feedback
        element.style.backgroundColor = '#c6f6d5';
        setTimeout(() => {
            element.style.backgroundColor = '';
        }, 500);
    }

    setupPullToRefresh() {
        const refreshContainers = document.querySelectorAll('.pull-to-refresh');

        refreshContainers.forEach(container => {
            let startY = 0;
            let currentY = 0;
            let isPulling = false;

            container.addEventListener('touchstart', (e) => {
                // Only trigger if scrolled to top
                if (container.scrollTop === 0) {
                    startY = e.touches[0].clientY;
                    isPulling = true;
                }
            }, { passive: true });

            container.addEventListener('touchmove', (e) => {
                if (!isPulling) return;

                currentY = e.touches[0].clientY;
                const diff = currentY - startY;

                if (diff > 0 && container.scrollTop === 0) {
                    e.preventDefault();
                    container.classList.add('pulling');

                    // Update indicator position
                    const indicator = container.querySelector('.pull-to-refresh-indicator');
                    if (indicator) {
                        indicator.style.top = Math.min(diff, 60) + 'px';
                    }
                }
            }, { passive: false });

            container.addEventListener('touchend', () => {
                if (!isPulling) return;

                const diff = currentY - startY;
                isPulling = false;

                if (diff > 80) {
                    // Trigger refresh
                    this.refreshContent(container);
                } else {
                    container.classList.remove('pulling');
                    const indicator = container.querySelector('.pull-to-refresh-indicator');
                    if (indicator) {
                        indicator.style.top = '-60px';
                    }
                }
            }, { passive: true });
        });
    }

    refreshContent(container) {
        // Show loading spinner
        const indicator = container.querySelector('.pull-to-refresh-indicator');
        if (indicator) {
            indicator.innerHTML = '<div class="pull-to-refresh-spinner"></div>';
        }

        // Reload the page or fetch fresh data
        setTimeout(() => {
            window.location.reload();
        }, 500);
    }

    setupMobileNavigation() {
        const menuToggle = document.querySelector('.mobile-menu-toggle');
        const navMenu = document.querySelector('.nav-menu');

        if (menuToggle && navMenu) {
            menuToggle.addEventListener('click', () => {
                navMenu.classList.toggle('active');

                // Update aria-expanded
                const isExpanded = navMenu.classList.contains('active');
                menuToggle.setAttribute('aria-expanded', isExpanded);

                // Change icon
                menuToggle.textContent = isExpanded ? '✕' : '☰';
            });

            // Close menu when clicking outside
            document.addEventListener('click', (e) => {
                if (!menuToggle.contains(e.target) && !navMenu.contains(e.target)) {
                    navMenu.classList.remove('active');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    menuToggle.textContent = '☰';
                }
            });

            // Close menu when clicking a link
            const navLinks = navMenu.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    navMenu.classList.remove('active');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    menuToggle.textContent = '☰';
                });
            });
        }
    }
}

// Touch gesture utilities
class TouchGestureUtils {
    static detectSwipeDirection(touchStartX, touchStartY, touchEndX, touchEndY) {
        const diffX = touchEndX - touchStartX;
        const diffY = touchEndY - touchStartY;
        const threshold = 50;

        if (Math.abs(diffX) > Math.abs(diffY)) {
            // Horizontal swipe
            if (Math.abs(diffX) > threshold) {
                return diffX > 0 ? 'right' : 'left';
            }
        } else {
            // Vertical swipe
            if (Math.abs(diffY) > threshold) {
                return diffY > 0 ? 'down' : 'up';
            }
        }

        return null;
    }

    static addTapEffect(element) {
        element.classList.add('ripple');

        element.addEventListener('touchstart', function(e) {
            const ripple = document.createElement('span');
            ripple.classList.add('ripple-effect');

            const rect = element.getBoundingClientRect();
            const x = e.touches[0].clientX - rect.left;
            const y = e.touches[0].clientY - rect.top;

            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';

            element.appendChild(ripple);

            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    }

    static preventZoom() {
        // Prevent double-tap zoom
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });
    }

    static handleKeyboardResize() {
        // Adjust viewport when keyboard appears
        const viewport = document.querySelector('meta[name=viewport]');

        if (viewport) {
            let resizeTimer;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => {
                    // Keyboard is shown
                    const windowHeight = window.innerHeight;
                    const documentHeight = document.documentElement.clientHeight;

                    if (windowHeight < documentHeight) {
                        document.body.classList.add('keyboard-visible');
                    } else {
                        document.body.classList.remove('keyboard-visible');
                    }
                }, 100);
            });
        }
    }
}

// Haptic feedback (for supported devices)
class HapticFeedback {
    static vibrate(pattern = 10) {
        if ('vibrate' in navigator) {
            navigator.vibrate(pattern);
        }
    }

    static light() {
        this.vibrate(10);
    }

    static medium() {
        this.vibrate(20);
    }

    static heavy() {
        this.vibrate([10, 20, 10]);
    }

    static success() {
        this.vibrate([10, 50, 10]);
    }

    static error() {
        this.vibrate([50, 100, 50]);
    }
}

// Initialize mobile gestures when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Only initialize on touch devices
    if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
        window.mobileGestures = new MobileGestures();
        TouchGestureUtils.preventZoom();
        TouchGestureUtils.handleKeyboardResize();

        // Add visual feedback to buttons
        document.querySelectorAll('.btn, button').forEach(btn => {
            TouchGestureUtils.addTapEffect(btn);
        });

        // Mark body as touch device
        document.body.classList.add('touch-device');
    }
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { MobileGestures, TouchGestureUtils, HapticFeedback };
}
