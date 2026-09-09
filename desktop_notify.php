<?php header('Content-Type: application/javascript'); ?>


<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (!("Notification" in window)) {
            console.log("This browser does not support desktop notification");
            return;
        }

        // Request permission on first user interaction if not already granted
        function requestNotificationPermission() {
            if (Notification.permission !== "granted" && Notification.permission !== "denied") {
                // Support both callback and promise-based APIs
                const callback = function (permission) {
                    if (permission === "granted") {
                        console.log("Notification permission granted.");
                        checkNotifications();
                    }
                };

                const result = Notification.requestPermission(callback);
                if (result && result.then) {
                    result.then(callback);
                }
            }
        }

        // Add listener for first click to trigger prompt (modern browsers require user gesture)
        document.addEventListener('click', requestNotificationPermission, { once: true });

        function checkNotifications() {
            fetch('/HMS/check_notifications.php')
                .then(response => response.json())
                .then(notifications => {
                    if (notifications.length > 0) {
                        notifications.forEach(notif => {
                            showDesktopNotification(notif.title, notif.message);
                        });
                    }
                })
                .catch(err => console.error('Error fetching notifications:', err));
        }

        function showDesktopNotification(title, message) {
            if (Notification.permission === "granted") {
                const options = {
                    body: message,
                    icon: '/HMS/images/hardware_logo.png', // Path to your logo
                    badge: '/HMS/images/hardware_logo.png'
                };
                const n = new Notification(title, options);

                n.onclick = function () {
                    window.focus();
                    // Redirect to notifications page based on folder (could be improved)
                    if (window.location.pathname.includes('/Admin/')) {
                        window.location.href = '/HMS/Admin/adminNotifications.php';
                    } else if (window.location.pathname.includes('/technician/')) {
                        window.location.href = '/HMS/technician/technicianNotifications.php';
                    } else if (window.location.pathname.includes('/storekeeper/')) {
                        window.location.href = '/HMS/storekeeper/storekeeperNotifications.php';
                    } else if (window.location.pathname.includes('/unithead/')) {
                        window.location.href = '/HMS/unithead/unitheadNotifications.php';
                    } else if (window.location.pathname.includes('/user/')) {
                        window.location.href = '/HMS/user/userNotifications.php';
                    }
                };
            }
        }

        // Check every 10 seconds
        setInterval(checkNotifications, 10000);

        // Initial check
        setTimeout(checkNotifications, 2000);
    });

</script>