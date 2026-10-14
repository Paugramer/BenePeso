<?php /* Original beneficiary table from 969802a; transaction controls removed. */ ?>
          <div class="table-wrap report-list-wrap">
              <table class="data-table report-list">
                  <thead>
                      <tr>
                           <th style="width: 62px; text-align: center;">No.</th>
                          <th>Profile</th>
                          <th>Barangay</th>
                          <th>Batch Code</th>
                          <th style="text-align: center;">Status</th>
                          <th style="text-align: center;">Details</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php 
                      $counter = $offset + 1;
                      foreach ($rows as $beneficiary): 
                          $bId = (int)$beneficiary["beneficiary_id"];
                          $bProgId = (int)$beneficiary["program_id"];
                          $beneficiaryApproval = $beneficiary["approval_status"] ?? "Pending";
                          $availmentStatus = $beneficiary["availment_status"] ?? "Not Yet Availed";
                          $availmentDisplay = $beneficiaryApproval === 'Pending'
                              ? 'Pending Review'
                              : ($beneficiaryApproval === 'Rejected' ? 'Application Not Accepted' : ($availmentStatus==='Exam Failed'?'Exam Not Passed':$availmentStatus));
                          $addedBy = trim($beneficiary["added_by_name"] ?? "Online Applicant"); 
                          
                          $dispName = trim($beneficiary["full_name"] ?? "");
                          if ($dispName === "" || strpos(strtolower($dispName), 'null null') !== false) {
                               $dispName = trim(($beneficiary["first_name"] ?? "") . " " . ($beneficiary["last_name"] ?? ""));
                          }
                          $initial = strtoupper(substr($dispName, 0, 1));
                          
                          $bData = $beneficiary;
                          $bData['program'] = $program;
                          $bData['added_by'] = $addedBy;
                          $bData['name'] = $dispName;
                          $bData['initial'] = $initial;
                          // Standardize names for JS
                          $bData['availment'] = $availmentStatus;
                          $bData['approval'] = $beneficiaryApproval;
                          $bData['id'] = $bId;
                           $profileFilename = basename((string)($beneficiary['user_profile_pic'] ?? ''));
                           $profileImage = $profileFilename !== '' && $profileFilename !== 'default_user.png' && is_file('uploads/' . $profileFilename) ? 'uploads/' . $profileFilename : '';
                           $bData['profile_image'] = $profileImage;

                          $profileData = json_encode($bData, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_INVALID_UTF8_SUBSTITUTE);
                      ?>
                      <tr class="table-row-animate clickable-row" data-profile='<?php echo module_escape($profileData); ?>' >
                          <td style="text-align: center; font-weight: 700; color: var(--muted); font-size: 13px;" onclick="event.stopPropagation();"><?php echo $counter++; ?></td>
                          <td>
                              <div style="display: flex; align-items: center; gap: 12px;">
                                  <div class="avatar-circle beneficiary-table-avatar" style="width: 40px; height: 40px; font-size: 15px;">
                                      <span><?php echo module_escape($initial); ?></span>
                                      <?php if ($profileImage !== ''): ?>
                                          <img src="<?php echo module_escape($profileImage); ?>" alt="<?php echo module_escape($dispName); ?> profile photo" loading="lazy" onerror="this.remove()">
                                      <?php endif; ?>
                                  </div>
                                  <div>
                                      <div style="font-weight: 800; color: var(--green-dark); font-size: 13.5px; text-transform: uppercase;"><?php echo module_escape($dispName); ?></div>
                                      <div style="font-size: 11.5px; color: var(--muted); margin-top: 2px;">Added <?php echo module_escape(!empty($beneficiary['created_at'])?date('M d, Y',strtotime($beneficiary['created_at'])):'?'); ?></div>
                                  </div>
                              </div>
                          </td>
                          <td style="font-weight: 600; text-transform: uppercase; color: var(--text); font-size: 13px;">
                              <i class="ph-fill ph-map-pin" style="color: var(--muted); margin-right:4px;"></i><?php echo module_escape($beneficiary["barangay"] ?? "—"); ?>
                          </td>
                          <td><div style="font-weight: 700; color: var(--muted); font-size: 12.5px;"><?php echo module_escape($beneficiary["program_code"] ?? "—"); ?></div></td>
                          <td style="text-align: center;">
                              <span class="<?php echo module_escape(($availmentStatus==='Completed'?'pill pill-green':'pill pill-orange')); ?>">
                                  <span class="pill-dot" style="background:currentColor;"></span> <?php echo module_escape($availmentDisplay); ?>
                              </span>
                          </td>
                          <td data-label="Details" style="text-align:center"><div class="action-menu-wrap" style="justify-content:center"><button class="btn-edit" type="button" data-view-report="<?=$bId?>"><i class="ph ph-eye"></i> View Record</button></div></td>
                      </tr>
                      <?php endforeach; ?>
                  </tbody>
              </table>
          </div>
