var OrderAlerts = (function() {
    let lastOrderId = 0;
    const POLLING_INTERVAL = 1000;
    const DISPLAY_DURATION = 5000;
    let isInitialized = false;
    let activeAlerts = new Set(); // Track active alerts to prevent overlap

    let audioContext = null;

    // Inject enhanced styles
    (function injectStyles() {
        if (document.getElementById('order-alert-style')) return;
        const style = document.createElement('style');
        style.id = 'order-alert-style';
        style.innerHTML = `
            .order-alert-container {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 15px;
                max-width: 420px;
                pointer-events: none;
                align-items: center;
            }
            
            .order-alert-card {
                background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
                border-radius: 16px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1), 
                            0 0 0 1px rgba(255, 255, 255, 0.9),
                            0 8px 30px rgba(76, 175, 124, 0.15);
                overflow: hidden;
                min-width: 380px;
                transform: scale(0.85);
                opacity: 0;
                animation: zoomInAlert 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
                pointer-events: auto;
                backdrop-filter: blur(10px);
                border: 1px solid rgba(229, 231, 235, 0.5);
                position: relative;
            }

            @keyframes zoomInAlert {
                from { opacity: 0; transform: scale(0.85); }
                to { opacity: 1; transform: scale(1); }
            }

            @keyframes zoomOutAlert {
                from { opacity: 1; transform: scale(1); }
                to { opacity: 0; transform: scale(0.85); }
            }
            
            .order-alert-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, #4CAF7C 0%, #2E7D32 50%, #4CAF7C 100%);
                background-size: 200% 100%;
                animation: shimmerLine 2s infinite linear;
            }
            
            .order-alert-header {
                background: linear-gradient(135deg, #4CAF7C 0%, #2E7D32 100%);
                color: white;
                padding: 18px 24px;
                display: flex;
                align-items: center;
                gap: 15px;
                position: relative;
                overflow: hidden;
            }
            
            .order-alert-header::after {
                content: '';
                position: absolute;
                top: -50%;
                left: -50%;
                width: 200%;
                height: 200%;
                background: linear-gradient(
                    to right,
                    transparent 20%,
                    rgba(255, 255, 255, 0.1) 50%,
                    transparent 80%
                );
                transform: rotate(30deg);
                animation: headerShimmer 3s infinite linear;
            }
            
            .order-alert-icon-container {
                background: rgba(255, 255, 255, 0.2);
                border-radius: 12px;
                width: 44px;
                height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(5px);
                border: 1px solid rgba(255, 255, 255, 0.3);
            }
            
            .order-alert-icon {
                font-size: 24px;
                filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
            }
            
            .order-alert-title {
                font-weight: 700;
                font-size: 17px;
                margin: 0;
                letter-spacing: -0.01em;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            }
            
            .order-alert-subtitle {
                font-size: 13px;
                opacity: 0.9;
                margin-top: 2px;
                font-weight: 500;
            }
            
            .order-alert-body {
                padding: 24px;
            }
            
            .order-alert-detail-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            
            .order-alert-detail-item {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            
            .order-alert-label {
                color: #6b7280;
                font-size: 13px;
                font-weight: 500;
                letter-spacing: 0.02em;
            }
            
            .order-alert-value {
                font-weight: 600;
                color: #111827;
                font-size: 15px;
            }
            
            .order-alert-amount {
                color: #4CAF7C;
                font-weight: 700;
                font-size: 16px;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            
            .order-alert-status {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 12px;
                background: linear-gradient(135deg, rgba(76, 175, 124, 0.1) 0%, rgba(46, 125, 50, 0.1) 100%);
                border-radius: 20px;
                font-size: 13px;
                font-weight: 600;
                color: #2E7D32;
                border: 1px solid rgba(76, 175, 124, 0.3);
            }
            
            .order-alert-footer {
                padding: 16px 24px;
                background: #f8fafc;
                border-top: 1px solid #f1f5f9;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .btn-view-order {
                background: linear-gradient(135deg, #4CAF7C 0%, #2E7D32 100%);
                color: white;
                border-radius: 10px;
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                border: none;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 4px 12px rgba(76, 175, 124, 0.3);
                position: relative;
                overflow: hidden;
            }
            
            .btn-view-order:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(76, 175, 124, 0.4);
            }
            
            .btn-view-order:active {
                transform: translateY(0);
            }
            
            .btn-view-order::after {
                content: '';
                position: absolute;
                top: 0;
                left: -100%;
                width: 100%;
                height: 100%;
                background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
                transition: left 0.7s;
            }
            
            .btn-view-order:hover::after {
                left: 100%;
            }
            
            .btn-dismiss {
                background: transparent;
                border: 1px solid #e5e7eb;
                color: #6b7280;
                border-radius: 8px;
                padding: 8px 16px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .btn-dismiss:hover {
                background: #f3f4f6;
                border-color: #d1d5db;
            }
            
            .alert-progress {
                height: 3px;
                background: linear-gradient(90deg, #4CAF7C, #2E7D32);
                width: 100%;
                transform-origin: left;
                animation: progressBar ${DISPLAY_DURATION}ms linear forwards;
            }
            
            .notification-badge {
                position: absolute;
                top: -8px;
                right: -8px;
                background: #ef4444;
                color: white;
                font-size: 12px;
                font-weight: 700;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                animation: pulse 2s infinite;
                border: 2px solid white;
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
            }
            
            /* Animations */
            @keyframes slideInRight {
                from {
                    transform: translateX(120%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes slideOutRight {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(120%);
                    opacity: 0;
                }
            }
            
            @keyframes progressBar {
                from {
                    transform: scaleX(1);
                }
                to {
                    transform: scaleX(0);
                }
            }
            
            @keyframes headerShimmer {
                0% {
                    transform: translateX(-100%) rotate(30deg);
                }
                100% {
                    transform: translateX(100%) rotate(30deg);
                }
            }
            
            @keyframes shimmerLine {
                0% {
                    background-position: 200% 0;
                }
                100% {
                    background-position: -200% 0;
                }
            }
            
            @keyframes pulse {
                0%, 100% {
                    transform: scale(1);
                }
                50% {
                    transform: scale(1.1);
                }
            }
            
            @keyframes float {
                0%, 100% {
                    transform: translateY(0);
                }
                50% {
                    transform: translateY(-5px);
                }
            }
            
            /* Animations */
        `;
        document.head.appendChild(style);
    })();

    function init() {
        if (isInitialized) return;
        
        // Setup audio unlocking
        const unlockAudio = () => {
             if (!audioContext) {
                 audioContext = new (window.AudioContext || window.webkitAudioContext)();
             }
             if (audioContext.state === 'suspended') {
                 audioContext.resume();
             }
        };
        
        document.addEventListener('click', unlockAudio);
        document.addEventListener('touchstart', unlockAudio);
        document.addEventListener('keydown', unlockAudio);
        
        // Create alert container
        const container = document.createElement('div');
        container.className = 'order-alert-container';
        container.id = 'order-alert-container';
        document.body.appendChild(container);

        fetch('/Index.php?ajax_check_orders=1&last_id=0')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    lastOrderId = data.latest_id;
                    isInitialized = true;
                    startPolling();
                }
            })
            .catch(err => console.error('OrderAlerts init error:', err));
    }


    function startPolling() {
        setInterval(() => {
            fetch('/Index.php?ajax_check_orders=1&last_id=' + lastOrderId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.new_orders && data.new_orders.length > 0) {
                        lastOrderId = data.latest_id;
                        data.new_orders.forEach((order, index) => {
                            setTimeout(() => showAlert(order), index * 6000);
                        });
                    }
                })
                .catch(err => console.error('OrderAlerts polling error:', err));
        }, POLLING_INTERVAL);
    }

    function playNotificationSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.setValueAtTime(523.25, audioContext.currentTime); // C5
            oscillator.frequency.setValueAtTime(659.25, audioContext.currentTime + 0.1); // E5
            oscillator.frequency.setValueAtTime(783.99, audioContext.currentTime + 0.2); // G5
            
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.1, audioContext.currentTime + 0.05);
            gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.5);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.5);
        } catch (e) {
            console.log('Audio context not supported');
        }
    }

    function showAlert(order) {
        if (activeAlerts.has(order.id)) return;
        activeAlerts.add(order.id);
        
        playNotificationSound();
        
        const container = document.getElementById('order-alert-container');
        const alertCount = container.children.length;
        
        const card = document.createElement('div');
        card.className = 'order-alert-card';
        card.dataset.orderId = order.id;
        
        const statusBadge = order.status.charAt(0).toUpperCase() + order.status.slice(1);
        
        card.innerHTML = `
            <div class="alert-progress"></div>
            ${alertCount > 0 ? '<div class="notification-badge">' + (alertCount + 1) + '</div>' : ''}
            <div class="order-alert-header">
                <div class="order-alert-icon-container">
                    <iconify-icon icon="solar:bag-check-bold" class="order-alert-icon"></iconify-icon>
                </div>
                <div>
                    <h4 class="order-alert-title">New Order Received!</h4>
                    <div class="order-alert-subtitle">Just now</div>
                </div>
            </div>
            <div class="order-alert-body">
                <div class="order-alert-detail-grid">
                    <div class="order-alert-detail-item">
                        <span class="order-alert-label">Order ID</span>
                        <span class="order-alert-value">#${order.order_number || order.id}</span>
                    </div>
                    <div class="order-alert-detail-item">
                        <span class="order-alert-label">Customer</span>
                        <span class="order-alert-value">${order.customer_name}</span>
                    </div>
                    <div class="order-alert-detail-item">
                        <span class="order-alert-label">Amount</span>
                        <span class="order-alert-amount">
                            <iconify-icon icon="solar:dollar-bold"></iconify-icon>
                            ${order.formatted_amount}
                        </span>
                    </div>
                    <div class="order-alert-detail-item">
                        <span class="order-alert-label">Status</span>
                        <span class="order-alert-status">
                            <iconify-icon icon="solar:check-circle-bold" style="font-size: 14px;"></iconify-icon>
                            ${statusBadge}
                        </span>
                    </div>
                </div>
            </div>
            <div class="order-alert-footer">
                <button class="btn-dismiss">Dismiss</button>
                <a href="/order/detail-order.php?id=${order.id}" class="btn-view-order">
                    <iconify-icon icon="solar:eye-bold"></iconify-icon>
                    View Order
                </a>
            </div>
        `;

        container.appendChild(card);
        
        // Add click handlers
        const dismissBtn = card.querySelector('.btn-dismiss');
        const viewOrderBtn = card.querySelector('.btn-view-order');
        
        dismissBtn.addEventListener('click', () => dismissAlert(card));
        viewOrderBtn.addEventListener('click', (e) => {
            e.preventDefault();
            dismissAlert(card);
            setTimeout(() => {
                window.location.href = `/order/detail-order.php?id=${order.id}`;
            }, 300);
        });
        
        // Auto-dismiss after duration
        setTimeout(() => dismissAlert(card), DISPLAY_DURATION);
    }

    function dismissAlert(card) {
        card.style.animation = 'zoomOutAlert 0.5s cubic-bezier(0.34, -0.56, 0.64, 1) forwards';
        
        setTimeout(() => {
            const orderId = card.dataset.orderId;
            if (orderId) activeAlerts.delete(parseInt(orderId));
            card.remove();
            
            // Update remaining badges
            const container = document.getElementById('order-alert-container');
            const alerts = container.querySelectorAll('.order-alert-card');
            alerts.forEach((alert, index) => {
                const badge = alert.querySelector('.notification-badge');
                if (badge) {
                    badge.textContent = index + 1;
                }
            });
        }, 500);
    }

    return {
        init: init,
        showAlert: showAlert,
        dismissAll: function() {
            const alerts = document.querySelectorAll('.order-alert-card');
            alerts.forEach(alert => dismissAlert(alert));
        }
    };
})();

// Auto-init on load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', OrderAlerts.init);
} else {
    OrderAlerts.init();
}