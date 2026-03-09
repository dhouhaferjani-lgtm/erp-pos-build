export function getDeviceId(): string {
  let deviceId = localStorage.getItem('izipos-device-id');
  if (!deviceId) {
    deviceId = crypto.randomUUID();
    localStorage.setItem('izipos-device-id', deviceId);
  }
  return deviceId;
}
