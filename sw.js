self.addEventListener("push", function (event) {
  var data = { title: "BoChi", body: "You have a session coming up.", url: "/" };
  try { if (event.data) { var j = event.data.json(); for (var k in j) data[k] = j[k]; } } catch (e) {}
  event.waitUntil(self.registration.showNotification(data.title, {
    body: data.body,
    icon: "icon-512.png",
    badge: "icon-512.png",
    data: { url: data.url || "/" }
  }));
});
self.addEventListener("notificationclick", function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || "/";
  event.waitUntil(clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) { if ("focus" in list[i]) return list[i].focus(); }
    return clients.openWindow(url);
  }));
});
