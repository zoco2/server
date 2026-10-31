'use strict';

/**
 * 把 GitHub 事件格式化成企业微信 Markdown，并推送到群机器人。
 * 事件内容只从环境变量读取，避免把标题、提交说明拼进 shell。
 */

const https = require('https');

/** 企业微信 markdown 内容上限（字节）。 */
const MAX_CONTENT_BYTES = 4000;

/** 单次推送里展示的提交条数。 */
const MAX_COMMITS = 10;

/**
 * 压缩外部文本，避免换行和方括号破坏 Markdown。
 * @param {unknown} value 原始文本
 * @param {number} max 最大字符数
 * @returns {string}
 */
function clip(value, max) {
  const text = String(value ?? '')
    .replace(/[\r\n\t]/g, ' ')
    .replace(/[\[\]`]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  if (!text) {
    return '';
  }
  if (text.length <= max) {
    return text;
  }
  return `${text.slice(0, Math.max(1, max - 1))}…`;
}

/**
 * 把时间格式化为北京时间。无法解析时返回截断后的原文。
 * @param {unknown} value ISO 时间
 * @returns {string}
 */
function formatTime(value) {
  const raw = String(value ?? '').trim();
  if (!raw) {
    return '';
  }
  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) {
    return clip(raw, 40);
  }
  return new Intl.DateTimeFormat('zh-CN', {
    timeZone: 'Asia/Shanghai',
    hour12: false,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

/**
 * 按 UTF-8 字节截断，保证不超过企业微信限制。
 * @param {string} text 消息正文
 * @param {number} maxBytes 最大字节数
 * @returns {string}
 */
function limitBytes(text, maxBytes) {
  let result = text;
  while (Buffer.byteLength(result, 'utf8') > maxBytes && result.length > 0) {
    result = result.slice(0, Math.ceil(result.length * 0.9));
  }
  if (result.length < text.length) {
    return `${result}\n…`;
  }
  return result;
}

/**
 * 读取 JSON 环境变量。空值或 null 时返回默认值。
 * @param {string | undefined} value 环境变量原文
 * @param {unknown} fallback 解析失败或为空时的默认值
 * @returns {unknown}
 */
function readJson(value, fallback) {
  if (!value || value === 'null') {
    return fallback;
  }
  return JSON.parse(value);
}

/**
 * 仓库主页链接。
 * @param {NodeJS.ProcessEnv} env 环境变量
 * @returns {string}
 */
function repoLink(env) {
  const server = env.SERVER_URL || 'https://github.com';
  const repo = env.REPO || '';
  return `[${repo}](${server}/${repo})`;
}

/**
 * 组装代码推送消息。
 * @param {NodeJS.ProcessEnv} env 环境变量
 * @returns {string}
 */
function buildPushMessage(env) {
  const commits = readJson(env.COMMITS_JSON, []);
  const list = Array.isArray(commits) ? commits : [];
  const server = env.SERVER_URL || 'https://github.com';
  const repo = env.REPO || '';
  const shown = list.slice(-MAX_COMMITS);
  const lines = [
    '## <font color="info">代码推送</font>',
    `>仓库：${repoLink(env)}`,
    `>分支：\`${clip(env.REF_NAME, 80) || '未知'}\``,
    `>推送人：${clip(env.ACTOR, 80) || '未知'}`,
    `>提交数：${list.length}`,
  ];

  const time = formatTime(env.EVENT_TIME);
  if (time) {
    lines.push(`>时间：${time}`);
  }
  if (env.FORCED === 'true') {
    lines.push('>方式：<font color="warning">强制推送</font>');
  }

  lines.push('');
  if (shown.length === 0) {
    lines.push('本次推送没有新的提交说明。');
  } else {
    shown.forEach((commit) => {
      const id = String(commit && commit.id ? commit.id : '');
      const shortId = id.slice(0, 7) || 'unknown';
      const url = id ? `${server}/${repo}/commit/${id}` : server;
      const message = clip(commit && commit.message, 80) || '（无说明）';
      const author = clip(commit && commit.author && commit.author.name, 40) || '未知';
      lines.push(`- [${shortId}](${url}) ${message}（${author}）`);
    });
  }

  if (list.length > shown.length) {
    lines.push('', `仅展示最近 ${shown.length} 条，共 ${list.length} 条。`);
  } else if (list.length >= 20) {
    lines.push('', 'GitHub 本次最多携带 20 条提交，更早的记录请打开变更链接。');
  }

  const compareUrl = String(env.COMPARE_URL || '').trim();
  if (compareUrl) {
    lines.push('', `[查看变更](${compareUrl})`);
  }

  return lines.join('\n');
}

/**
 * 组装 Pull Request 新建或合并消息。
 * @param {NodeJS.ProcessEnv} env 环境变量
 * @returns {string}
 */
function buildPullRequestMessage(env) {
  const merged = env.PR_MERGED === 'true';
  const title = merged ? 'Pull Request 已合并' : '新建 Pull Request';
  const color = merged ? 'info' : 'warning';
  const number = clip(env.PR_NUMBER, 20);
  const prTitle = clip(env.PR_TITLE, 120) || '（无标题）';
  const prUrl = String(env.PR_URL || '').trim();
  const headline = prUrl ? `[#${number} ${prTitle}](${prUrl})` : `#${number} ${prTitle}`;
  const lines = [
    `## <font color="${color}">${title}</font>`,
    `>仓库：${repoLink(env)}`,
    `>PR：${headline}`,
    `>作者：${clip(env.PR_USER, 80) || '未知'}`,
    `>分支：\`${clip(env.PR_HEAD, 80) || '未知'}\` → \`${clip(env.PR_BASE, 80) || '未知'}\``,
  ];

  if (merged) {
    lines.push(`>合并人：${clip(env.MERGED_BY, 80) || clip(env.ACTOR, 80) || '未知'}`);
  }

  const time = formatTime(env.EVENT_TIME);
  if (time) {
    lines.push(`>时间：${time}`);
  }

  return lines.join('\n');
}

/**
 * 组装 Issue 标题链接。
 * @param {NodeJS.ProcessEnv} env 环境变量
 * @returns {string}
 */
function issueHeadline(env) {
  const number = clip(env.ISSUE_NUMBER, 20);
  const title = clip(env.ISSUE_TITLE, 120) || '（无标题）';
  const url = String(env.ISSUE_URL || '').trim();
  return url ? `[#${number} ${title}](${url})` : `#${number} ${title}`;
}

/**
 * 组装新建 Issue 消息。
 * @param {NodeJS.ProcessEnv} env 环境变量
 * @returns {string}
 */
function buildIssueMessage(env) {
  const lines = [
    '## <font color="warning">新建 Issue</font>',
    `>仓库：${repoLink(env)}`,
    `>Issue：${issueHeadline(env)}`,
    `>创建人：${clip(env.ISSUE_USER, 80) || '未知'}`,
  ];

  const time = formatTime(env.EVENT_TIME);
  if (time) {
    lines.push(`>时间：${time}`);
  }

  return lines.join('\n');
}

/**
 * 组装 Issue 新回复消息。编辑和删除评论不会进入这里。
 * @param {NodeJS.ProcessEnv} env 环境变量
 * @returns {string}
 */
function buildIssueCommentMessage(env) {
  const excerpt = clip(env.COMMENT_BODY, 300) || '（无内容）';
  const commentUrl = String(env.COMMENT_URL || '').trim();
  const lines = [
    '## <font color="comment">Issue 回复</font>',
    `>仓库：${repoLink(env)}`,
    `>Issue：${issueHeadline(env)}`,
    `>回复人：${clip(env.COMMENT_USER, 80) || clip(env.ACTOR, 80) || '未知'}`,
  ];

  const time = formatTime(env.EVENT_TIME);
  if (time) {
    lines.push(`>时间：${time}`);
  }

  lines.push('', excerpt);
  if (commentUrl) {
    lines.push('', `[查看回复](${commentUrl})`);
  }

  return lines.join('\n');
}

/**
 * 按 GitHub 事件生成企业微信 Markdown。
 * @param {NodeJS.ProcessEnv} env 环境变量
 * @returns {string}
 */
function buildMessage(env) {
  let content = '';
  if (env.EVENT_NAME === 'push') {
    content = buildPushMessage(env);
  } else if (env.EVENT_NAME === 'pull_request_target' || env.EVENT_NAME === 'pull_request') {
    content = buildPullRequestMessage(env);
  } else if (env.EVENT_NAME === 'issues') {
    content = buildIssueMessage(env);
  } else if (env.EVENT_NAME === 'issue_comment') {
    content = buildIssueCommentMessage(env);
  } else {
    throw new Error(`未处理的 GitHub 事件：${env.EVENT_NAME || '空'}`);
  }
  return limitBytes(content, MAX_CONTENT_BYTES);
}

/**
 * 调用企业微信群机器人 Webhook。
 * @param {string} webhookUrl Webhook 完整地址
 * @param {string} content Markdown 正文
 * @returns {Promise<{errcode?: number, errmsg?: string}>}
 */
function sendWecom(webhookUrl, content) {
  const body = JSON.stringify({
    msgtype: 'markdown',
    markdown: { content },
  });
  const url = new URL(webhookUrl);

  return new Promise((resolve, reject) => {
    const request = https.request({
      protocol: url.protocol,
      hostname: url.hostname,
      path: `${url.pathname}${url.search}`,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
      },
    }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let payload;
        try {
          payload = JSON.parse(raw);
        } catch (error) {
          reject(new Error(`企业微信返回无法解析：${raw.slice(0, 200)}`));
          return;
        }
        if (response.statusCode && response.statusCode >= 400) {
          reject(new Error(`企业微信 HTTP ${response.statusCode}：${payload.errmsg || raw.slice(0, 200)}`));
          return;
        }
        resolve(payload);
      });
    });

    request.on('error', reject);
    request.write(body);
    request.end();
  });
}

/**
 * 读取环境变量、生成消息并推送。
 * DRY_RUN=1 时只打印消息，不访问 Webhook。
 * @returns {Promise<void>}
 */
async function main() {
  const content = buildMessage(process.env);
  if (process.env.DRY_RUN === '1') {
    process.stdout.write(`${content}\n`);
    return;
  }

  const webhookUrl = process.env.WEBHOOK_URL || '';
  if (!webhookUrl) {
    throw new Error('缺少 GitHub Secret：WECHAT_WEBHOOK_URL');
  }

  const result = await sendWecom(webhookUrl, content);
  if (!result || result.errcode !== 0) {
    const reason = result && result.errmsg ? result.errmsg : '未知错误';
    throw new Error(`企业微信推送失败：${reason}`);
  }
  process.stdout.write('企业微信推送成功\n');
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exit(1);
  });
}

module.exports = {
  buildMessage,
  clip,
  formatTime,
  limitBytes,
};
