# EdgePay

### Verified payments. Organized checkout.

EdgePay is an ESP32-based dynamic QR payment terminal designed for unattended retail, vending machines, and kiosk systems.

The system creates a unique payment transaction, displays a dynamic QR code, verifies the payment through the backend, and triggers a connected actuator only after successful payment verification.

---

## Features

- ESP32-based payment terminal
- Dynamic QR payment flow
- Cashfree payment gateway integration
- PHP REST backend
- MySQL transaction management
- Payment status verification
- 5-minute transaction expiry
- Duplicate-dispense protection
- Failed payment handling
- Device authentication using API key
- TFT display support
- Relay/motor control support
- Webhook support
- LAN-based development architecture
- Environment-based secret management

---

## System Architecture

```text
Customer
   |
   | Scan QR
   v
ESP32 + TFT
   |
   | Create Payment
   v
EdgePay PHP Backend
   |
   +---- MySQL
   |
   v
Cashfree
   |
   | Payment
   v
Customer UPI App
   |
   v
Cashfree Verification
   |
   v
EdgePay Backend
   |
   | SUCCESS + dispense=true
   v
ESP32
   |
   v
Relay / Motor