<?php
declare(strict_types=1);
?>
<h2>Demographics</h2>
<div class="form-grid">
  <div><label class="req" for="first_name">First name</label><input id="first_name" name="first_name" value="<?= e($f['first_name']) ?>" maxlength="80" required></div>
  <div><label class="req" for="last_name">Last name</label><input id="last_name" name="last_name" value="<?= e($f['last_name']) ?>" maxlength="80" required></div>
  <div><label for="national_id">National ID</label><input id="national_id" name="national_id" value="<?= e($f['national_id']) ?>" maxlength="30"></div>
  <div><label class="req" for="date_of_birth">Date of birth</label><input id="date_of_birth" type="date" name="date_of_birth" value="<?= e($f['date_of_birth']) ?>" max="<?= date('Y-m-d') ?>" required></div>
  <div><label class="req" for="gender">Gender</label><select id="gender" name="gender">
    <?php foreach (['female', 'male', 'other'] as $gender): ?><option value="<?= e($gender) ?>" <?= $f['gender'] === $gender ? 'selected' : '' ?>><?= ucfirst($gender) ?></option><?php endforeach; ?></select></div>
  <div><label class="req" for="phone">Phone</label><input id="phone" type="tel" name="phone" value="<?= e($f['phone']) ?>" maxlength="30" required></div>
  <div><label for="email">Email</label><input id="email" type="email" name="email" value="<?= e($f['email']) ?>" maxlength="160"></div>
  <div class="full"><label for="address">Address</label><input id="address" name="address" value="<?= e($f['address']) ?>" maxlength="255"></div>
  <div><label for="next_of_kin">Next of kin</label><input id="next_of_kin" name="next_of_kin" value="<?= e($f['next_of_kin']) ?>" maxlength="120"></div>
  <div><label for="next_of_kin_phone">Next of kin phone</label><input id="next_of_kin_phone" name="next_of_kin_phone" value="<?= e($f['next_of_kin_phone']) ?>" maxlength="30"></div>
  <div><label for="insurance_provider">Medical aid type</label><select id="insurance_provider" name="insurance_provider">
    <option value="">No medical aid / self-pay</option>
    <?php foreach ($medicalAidTypes as $type): ?><option value="<?= e($type) ?>" <?= $f['insurance_provider'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?>
  </select></div>
  <div><label for="insurance_no">Medical aid / member no.</label><input id="insurance_no" name="insurance_no" value="<?= e($f['insurance_no']) ?>" maxlength="60"></div>
</div>
<h2 class="medical-history-heading">Medical history</h2>
<div class="form-grid">
  <div><label for="blood_group">Blood group</label><select id="blood_group" name="blood_group">
    <?php foreach (['unknown','A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bloodGroup): ?><option value="<?= e($bloodGroup) ?>" <?= $f['blood_group'] === $bloodGroup ? 'selected' : '' ?>><?= e($bloodGroup) ?></option><?php endforeach; ?></select></div>
  <?php if ($showPriority): ?>
    <div><label for="priority">Arrival priority</label><select id="priority" name="priority">
      <?php foreach (['routine', 'urgent', 'emergency'] as $priority): ?><option value="<?= e($priority) ?>" <?= $f['priority'] === $priority ? 'selected' : '' ?>><?= ucfirst($priority) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="full"><label for="allergies">Allergies</label><textarea id="allergies" name="allergies" maxlength="1000"><?= e($f['allergies']) ?></textarea></div>
  <div class="full"><label for="chronic_conditions">Chronic conditions</label><textarea id="chronic_conditions" name="chronic_conditions" maxlength="1000"><?= e($f['chronic_conditions']) ?></textarea></div>
  <div class="full"><label for="medical_history">Other medical history</label><textarea id="medical_history" name="medical_history" maxlength="3000"><?= e($f['medical_history']) ?></textarea></div>
</div>
