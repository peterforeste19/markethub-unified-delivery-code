# 🚀 MarketHub Delivery System - Quick Start Guide

## Installation (5 Minutes)

### Step 1: Upload Plugin Files
```bash
1. Go to WordPress Admin → Plugins → Add New → Upload Plugin
2. Upload: markethub-role-management.php
3. Click "Activate Plugin"
4. Upload: markethub-unified-driver-app.php
5. Click "Activate Plugin"
```

### Step 2: Verify Pages Created
Both pages are auto-created:
- ✅ `/driver-app/` - Driver application
- ✅ `/employee-confirmation/` - Employee panel

### Step 3: Initial Setup (Optional)
If you have existing employees in custom table:
```
1. Go to: Tools → MH Employee Migration
2. Click "Migrate Employees to WordPress Users"
3. Done! Employees can now login with same credentials
```

---

## 🎯 Quick Usage Guide

### For Drivers

#### Registration
1. Go to `/my-account/` (WooCommerce My Account page)
2. Click "Register"
3. Fill in details
4. Select **"🚗 Driver (Deliver orders)"**
5. Register → Account created with pending status
6. Login → **Automatically activated!**

#### Using Driver App
1. Visit `/driver-app/`
2. Login with your credentials
3. See two sections:
   - **Pending Orders** - Available to claim
   - **My Claimed Orders** - Your active deliveries

#### Claiming & Delivering
```
1. Click pending order → Expand details
2. Click "Accept Order" → Moves to "My Claimed Orders"
3. Click claimed order → "Start Fulfillment"
4. See full-screen map with route
5. View order items (click to see images)
6. Collect all items
7. Click "Items Collected - Start Delivery" → Timer starts
8. Deliver to customer
9. Capture POD:
   - Select ID type
   - Photo of ID (front + back)
   - Customer signature
10. Click "Complete Delivery" → Done!
```

### For Employees

#### Registration
1. Go to `/my-account/`
2. Click "Register"
3. Fill in details
4. Select **"💼 Employee (Office/Admin)"**
5. Register → Status: Pending admin approval
6. **Wait for admin approval email**

#### After Approval
1. Login at `/employee-confirmation/`
2. View pending payment confirmations
3. Approve/Reject orders

### For Administrators

#### Managing Applicants
1. Go to: **WordPress Admin → MarketHub Mgmt**
2. See dashboard with:
   - Pending employee applications
   - Active employees count
   - Active drivers count

#### Approving Employees
```
1. Find pending employee in list
2. Click "✅ Approve" → Employee activated, email sent
   OR
   Click "❌ Reject" → Application deleted
```

#### Viewing Delivery Data
1. Go to: **WooCommerce → Orders**
2. Open any completed order
3. See meta boxes:
   - **🚗 Driver & Delivery Information** (right sidebar)
     - Driver name
     - All timestamps
     - Delivery duration
   - **📋 Proof of Delivery** (main content)
     - ID type
     - ID photos (front/back)
     - Customer signature

---

## 🔑 Key URLs

| Page | URL | Who Can Access |
|------|-----|----------------|
| Driver App | `/driver-app/` | Active drivers only |
| Employee Portal | `/employee-confirmation/` | Active employees only |
| Admin Panel | `/wp-admin/admin.php?page=markethub-management` | Administrators |
| Registration | `/my-account/` | Everyone |

---

## 📱 Testing It Works

### Test 1: Driver Flow (5 mins)
```
1. Register new user as driver
2. Login → Should auto-activate
3. Visit /driver-app/
4. Should see pending orders (if any exist)
5. Claim an order
6. Should see map with route
✅ Success!
```

### Test 2: Employee Flow (3 mins)
```
1. Register new user as employee
2. Try to login → Should see pending message
3. Admin approves employee
4. Login again → Should work
5. Visit /employee-confirmation/
✅ Success!
```

### Test 3: Order Completion (10 mins)
```
1. Login as driver
2. Claim a processing order
3. Start fulfillment
4. View items and map
5. Start delivery (timer appears)
6. Capture ID photo
7. Draw signature
8. Complete delivery
9. Admin views order → All POD data visible
✅ Success!
```

---

## ⚙️ Configuration

### No Configuration Required!
Everything works out of the box:
- ✅ Google Maps API key included
- ✅ Roles automatically created
- ✅ Pages automatically created
- ✅ Order statuses registered
- ✅ REST API endpoints active

### Optional Customization
Edit in PHP files if needed:
- Token expiry (default: 24 hours)
- Auto-refresh interval (default: 30 seconds)
- Image compression quality (default: 75%)
- Max image size (default: 1200px)

---

## 🐛 Common Issues & Fixes

### Issue: Driver can't login
**Fix:** Check role assignment
```sql
-- In phpMyAdmin, check wp_usermeta:
SELECT * FROM wp_usermeta WHERE meta_key = 'wp_capabilities' AND user_id = [USER_ID];
-- Should show: a:1:{s:18:"markethub_drivers";b:1;}
```

### Issue: Map not showing
**Fix:** Open browser console (F12)
- If "Google Maps API error" → API key issue
- If "Cannot read property 'lat'" → GPS coordinates missing in order
- If blank screen → Check React errors in console

### Issue: Can't see POD images in admin
**Fix:** Check permissions
```bash
# In SSH:
cd wp-content/uploads/
ls -la markethub_pods/
# Should see .htaccess and image files
# If permission denied, fix with:
chmod 755 markethub_pods/
```

### Issue: Orders not appearing in driver app
**Fix:** Check order status
- Orders must be in "Processing" status
- Orders must NOT have `_mh_driver_id` meta
- Check in WooCommerce → Orders

---

## 📞 Quick Reference

### Order Status Flow
```
Pending Payment
    ↓
Processing ← Orders appear in driver app
    ↓
Driver Claimed ← Driver accepted order
    ↓
Out for Delivery ← Driver started delivery (timer)
    ↓
Completed ← POD captured, delivery complete
```

### User Role Flow
```
NEW DRIVER:
Register → markethub_pending → First Login → markethub_drivers

NEW EMPLOYEE:
Register → markethub_pending → Admin Approval → markethub_employees

NEW CUSTOMER:
Register → customer (unchanged)
```

### Critical Meta Keys
```php
// Customer GPS (from checkout)
_markethub_customer_lat
_markethub_customer_lng

// Driver assignment
_mh_driver_id
_mh_driver_name
_mh_claimed_time

// Delivery tracking
_mh_delivery_start_time
_mh_delivery_complete_time
_mh_delivery_duration

// Proof of delivery
_mh_signature_path
_mh_id_front_path
_mh_id_back_path
_mh_id_type
```

---

## 🎉 That's It!

You now have:
- ✅ Automated driver activation
- ✅ Employee approval system
- ✅ Full-featured driver app
- ✅ Google Maps routing
- ✅ Proof of delivery capture
- ✅ Complete admin visibility
- ✅ Mobile-responsive design
- ✅ Secure image storage
- ✅ Race condition prevention
- ✅ Integration with existing delivery system

**Everything is ready to use!**

---

## 📚 Need More Details?

See `IMPLEMENTATION_GUIDE.md` for:
- Complete feature list
- API documentation
- Security details
- Database schema
- Troubleshooting guide
- Deployment checklist

---

**Happy Delivering! 🚗📦**
