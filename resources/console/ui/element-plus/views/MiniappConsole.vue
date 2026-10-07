<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>小程序发布</span>
        </div>
      </template>

      <el-tabs v-model="activeTab">
        <!-- ═══════════ 平台配置 ═══════════ -->
        <el-tab-pane label="平台配置" name="config">
          <el-form label-width="160px" class="config-form">
            <el-divider content-position="left">公共配置</el-divider>
            <el-form-item label="小程序名称">
              <el-input v-model="common.name" placeholder="展示名（写入 manifest name）" maxlength="30" />
            </el-form-item>
            <el-form-item label="版本号">
              <el-input v-model="common.version_name" placeholder="如 1.0.0（versionName）" maxlength="20" class="inline-input" />
              <span class="field-sep" />
              <el-input v-model.number="common.version_code" type="number" placeholder="整数（versionCode）" class="inline-input" />
            </el-form-item>
            <el-form-item label="租户 API 域名">
              <el-input v-model="common.api_domain" placeholder="如 api.club.example.com（留空默认租户域名，构建产物注入 https://）" />
              <div class="form-tip">构建产物将以此域名访问后端接口（request 合法域名需 https + 备案，在小程序后台配置）</div>
            </el-form-item>

            <el-divider content-position="left">微信登录凭证（只读）</el-divider>

            <el-alert
              type="info"
              :closable="false"
              show-icon
              title="登录凭证与开关由「第三方登录 → 微信」统一管理"
              description="小程序登录的 AppID / AppSecret / 启用开关已上移框架统一维护，此处只读展示；打包构建自动复用同一 AppID（单写多读，同源不漂移）。如需修改请前往「第三方登录 → 微信」。"
            />
            <el-form-item label="接入形态">
              <el-tag :type="authMode === 'component' ? 'success' : 'info'" size="small">
                {{ authMode === 'component' ? '服务商授权' : '自建凭证' }}
              </el-tag>
            </el-form-item>
            <el-form-item label="登录开关">
              <el-tag :type="mpWeixin.enabled ? 'success' : 'danger'" size="small">
                {{ mpWeixin.enabled ? '已启用（允许登录与构建）' : '停用（登录与构建将被拒绝）' }}
              </el-tag>
            </el-form-item>
            <el-form-item label="AppID">
              <span class="readonly-value">{{ mpWeixin.appid || '（未配置）' }}</span>
            </el-form-item>
            <el-form-item label="AppSecret">
              <span class="readonly-value">{{ appSecretConfigured ? '已配置（********）' : '（未配置）' }}</span>
            </el-form-item>

            <!-- component 形态：补充授权信息（只读） -->
            <el-form-item v-if="authMode === 'component' && componentInfo" label="授权信息">
              <el-descriptions :column="1" size="small" border class="auth-descriptions">
                <el-descriptions-item label="小程序昵称">{{ componentInfo.nickname || '—' }}</el-descriptions-item>
                <el-descriptions-item label="授权 AppID">{{ componentInfo.appid || '—' }}</el-descriptions-item>
                <el-descriptions-item label="授权时间">{{ componentInfo.authorized_at || '—' }}</el-descriptions-item>
              </el-descriptions>
            </el-form-item>

            <el-form-item>
              <el-button type="primary" plain @click="goWechatLogin">前往第三方登录管理凭证</el-button>
            </el-form-item>

            <el-divider content-position="left">打包调试</el-divider>
            <el-form-item label="跳过域名校验">
              <el-switch v-model="mpWeixin.url_check" />
              <span class="switch-hint">{{ mpWeixin.url_check ? '开（仅开发期：不校验 request 合法域名）' : '关（生产：校验合法域名）' }}</span>
            </el-form-item>

            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- ═══════════ 构建记录 ═══════════ -->
        <el-tab-pane label="构建记录" name="builds">
          <div class="filter-bar">
            <el-select v-model="buildPlatform" disabled style="width: 180px">
              <el-option label="微信小程序" value="mp-weixin" />
            </el-select>
            <el-button type="primary" :loading="building" @click="handleCreateBuild">发起构建</el-button>
            <el-button @click="loadBuilds">刷新</el-button>
          </div>

          <el-table v-loading="buildLoading" :data="builds" stripe style="width: 100%">
            <el-table-column label="构建 ID" width="200">
              <template #default="{ row }">{{ row.build_id }}</template>
            </el-table-column>
            <el-table-column label="平台" width="120">
              <template #default="{ row }">{{ platformLabel(row.platform) }}</template>
            </el-table-column>
            <el-table-column label="形态" width="100">
              <template #default="{ row }">
                {{ row.config_snapshot?.auth_mode === 'component' ? '服务商' : '自建' }}
              </template>
            </el-table-column>
            <el-table-column label="版本" min-width="130">
              <template #default="{ row }">
                {{ row.config_snapshot?.common?.version_name || '—' }}
              </template>
            </el-table-column>
            <el-table-column label="状态" width="110">
              <template #default="{ row }">
                <el-tag :type="statusTagType(row.status)" size="small">
                  {{ statusLabel(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="发起时间" width="170">
              <template #default="{ row }">{{ row.created_at || '—' }}</template>
            </el-table-column>
            <el-table-column label="完成时间" width="170">
              <template #default="{ row }">{{ row.finished_at || '—' }}</template>
            </el-table-column>
            <el-table-column label="操作" width="150" fixed="right">
              <template #default="{ row }">
                <el-button v-if="row.status === 'success'" link type="success" @click="downloadArtifact(row)">下载产物</el-button>
                <el-button v-if="row.status === 'failed'" link type="danger" @click="openError(row)">查看原因</el-button>
                <span v-if="row.status === 'queued' || row.status === 'building'" class="status-hint">构建中…</span>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 构建失败详情 -->
    <el-drawer v-model="errorDrawerVisible" title="构建失败详情" size="480px">
      <el-alert v-if="errorDetail" type="error" :closable="false" :title="errorDetail.error || '未知错误'" />
      <div class="drawer-section-label">构建日志（末段）</div>
      <pre class="log-view">{{ errorDetail?.last_log || '（无日志）' }}</pre>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { http } from '@scrm/shared'

const router = useRouter()

// ═══════════════════════ 平台配置 ═══════════════════════
interface ComponentInfo {
  nickname: string
  appid: string
  authorized_at: string | null
}
interface MpWeixinConfig {
  enabled: boolean
  appid: string
  app_secret_masked: string | null
  url_check: boolean
  component?: ComponentInfo
}
interface CommonConfig {
  name: string
  version_name: string
  version_code: number | string
  api_domain: string
}
interface MiniappConfig {
  auth_mode: 'component' | 'self'
  common: CommonConfig
  mp_weixin: MpWeixinConfig
}

const activeTab = ref('config')
const configLoading = ref(false)
const saving = ref(false)
const authMode = ref<'component' | 'self'>('self')
const common = ref<CommonConfig>({ name: '', version_name: '', version_code: '', api_domain: '' })
const mpWeixin = ref<MpWeixinConfig>({ enabled: false, appid: '', app_secret_masked: null, url_check: false })
const componentInfo = ref<ComponentInfo | null>(null)

/** 框架登录 secret 是否已配置（只读展示掩码，值取自框架 oauth 组） */
const appSecretConfigured = computed(() => !!mpWeixin.value.app_secret_masked)

async function loadConfig() {
  configLoading.value = true
  try {
    const res = (await http.get('/biz/miniapp/config')) as any
    const data = res?.data ?? res
    authMode.value = data.auth_mode === 'component' ? 'component' : 'self'
    common.value = { ...common.value, ...(data.common || {}) }
    const mp = data.mp_weixin || {}
    mpWeixin.value = {
      enabled: !!mp.enabled,
      appid: mp.appid || '',
      app_secret_masked: mp.app_secret_masked ?? null,
      url_check: !!mp.url_check,
    }
    componentInfo.value = mp.component || null
  } catch (e: any) {
    ElMessage.error(e?.message || '加载配置失败')
  } finally {
    configLoading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    // 仅提交打包配置（common + url_check）；登录凭证/开关归框架微信登录页，不在此写入
    const payload: Record<string, any> = {
      common: {
        name: common.value.name,
        version_name: common.value.version_name,
        version_code: common.value.version_code,
        api_domain: common.value.api_domain,
      },
      mp_weixin: {
        url_check: mpWeixin.value.url_check,
      },
    }

    await http.put('/biz/miniapp/config', payload)
    ElMessage.success('配置已保存')
    await loadConfig()
  } catch (e: any) {
    ElMessage.error(e?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

/** 微信登录配置入口（框架「第三方登录 → 微信」tab：三载体登录凭证 + 小程序登录开关统一管理） */
function goWechatLogin() {
  router.push('/oauth?tab=wechat')
}

// ═══════════════════════ 构建记录 ═══════════════════════
type BuildStatus = 'queued' | 'building' | 'success' | 'failed'
interface BuildRecord {
  build_id: string
  platform: string
  channel: string
  status: BuildStatus
  config_snapshot: { auth_mode?: string; common?: { version_name?: string } } | null
  error: string | null
  last_log: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string | null
}

const buildPlatform = ref('mp-weixin')
const builds = ref<BuildRecord[]>([])
const buildLoading = ref(false)
const building = ref(false)
const errorDrawerVisible = ref(false)
const errorDetail = ref<BuildRecord | null>(null)
let pollTimer: ReturnType<typeof setInterval> | null = null

function statusLabel(status: BuildStatus): string {
  const map: Record<BuildStatus, string> = {
    queued: '排队中',
    building: '构建中',
    success: '成功',
    failed: '失败',
  }
  return map[status] || status
}

function statusTagType(status: BuildStatus): 'info' | 'warning' | 'success' | 'danger' {
  const map: Record<BuildStatus, 'info' | 'warning' | 'success' | 'danger'> = {
    queued: 'info',
    building: 'warning',
    success: 'success',
    failed: 'danger',
  }
  return map[status] || 'info'
}

function platformLabel(platform: string): string {
  const map: Record<string, string> = { 'mp-weixin': '微信小程序' }
  return map[platform] || platform
}

async function loadBuilds() {
  buildLoading.value = true
  try {
    const res = (await http.get(`/biz/miniapp/builds?platform=${buildPlatform.value}`)) as any
    const data = res?.data ?? res
    builds.value = Array.isArray(data) ? data : []
    // 有进行中任务 → 3s 轮询直至终态；无任务则停轮询（省无效请求）
    const inflight = builds.value.some(b => b.status === 'queued' || b.status === 'building')
    if (inflight) ensurePolling()
    else stopPolling()
  } catch (e: any) {
    ElMessage.error(e?.message || '加载构建记录失败')
  } finally {
    buildLoading.value = false
  }
}

function ensurePolling() {
  if (pollTimer) return
  pollTimer = setInterval(() => { loadBuilds() }, 3000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function handleCreateBuild() {
  building.value = true
  try {
    await http.post('/biz/miniapp/builds', { platform: buildPlatform.value })
    ElMessage.success('构建任务已发起')
    await loadBuilds() // 进入排队态后由轮询接管
  } catch (e: any) {
    ElMessage.error(e?.message || '发起构建失败')
  } finally {
    building.value = false
  }
}

async function downloadArtifact(row: BuildRecord) {
  try {
    // 需携带 auth token 下载，走共享 http（responseType blob 由拦截器原样透出）
    const blob = (await http.get(`/biz/miniapp/builds/${row.build_id}/artifact`, {
      responseType: 'blob',
    } as any)) as unknown as Blob
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `miniapp-build-${row.build_id}.zip`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    ElMessage.error(e?.message || '下载失败（产物可能已被清理）')
  }
}

function openError(row: BuildRecord) {
  errorDetail.value = row
  errorDrawerVisible.value = true
}

onMounted(() => {
  loadConfig()
  loadBuilds()
})
onBeforeUnmount(stopPolling)
</script>

<style scoped lang="scss">
.page-container {
  padding: 20px;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.config-form {
  max-width: 720px;
}
.inline-input {
  width: 220px;
}
.field-sep {
  display: inline-block;
  width: 12px;
}
.form-tip {
  width: 100%;
  font-size: 12px;
  color: #909399;
  line-height: 1.5;
  margin-top: 4px;
}
.switch-hint {
  margin-left: 10px;
  font-size: 13px;
  color: #909399;
}
.readonly-value {
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
  color: #303133;
  word-break: break-all;
}
.auth-descriptions {
  width: 100%;
}
.filter-bar {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}
.status-hint {
  font-size: 13px;
  color: #909399;
}
.drawer-section-label {
  margin: 20px 0 8px;
  font-size: 13px;
  color: #606266;
}
.log-view {
  background: #f5f7fa;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  padding: 10px;
  font-size: 12px;
  line-height: 1.6;
  max-height: 50vh;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
  color: #303133;
}
</style>
