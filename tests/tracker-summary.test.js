const {test}=require('node:test');const assert=require('node:assert/strict');
const {summarize}=require('../assets/tracker-connector.js');
test('DSA totals include actual journal time but exclude solutions and notes',()=>{
 const m=summarize('dsa',{questions:[{status:'Solved',bruteForceSolution:'private code'},{status:'In Progress'},{status:'Not Started'}],attemptsByQuestion:{a:[{minutes:25,notes:'private note'},{minutes:12}]},reviews_due:1});
 assert.deepEqual(m,{source:'dsa',total:3,completed:1,in_progress:1,attempts:2,minutes:37,reviews_due:1});assert.ok(!JSON.stringify(m).includes('private'));
});
test('cloud counts selected projects and excludes deleted/catalog-only records',()=>{
 const m=summarize('cloud',[{inPlan:true,status:'Completed'},{inPlan:false,status:'Not started'},{custom:true,status:'In progress'},{inPlan:true,deleted:true,status:'Completed'}]);assert.equal(m.total,2);assert.equal(m.completed,1);assert.equal(m.in_progress,1);
});
test('SQL preserves solved and revision state without sending query contents',()=>{
 const m=summarize('sql',{questions:[{status:'Solved',attempts:2,optimizedQuery:'SELECT secret'},{status:'Review',attempts:3}]});assert.equal(m.completed,1);assert.equal(m.in_progress,1);assert.equal(m.attempts,5);assert.equal(m.minutes,0);assert.ok(!JSON.stringify(m).includes('SELECT'));
});
test('web archived items do not inflate the active catalog',()=>{
 const m=summarize('web',[{status:'Completed'},{status:'Blocked'},{status:'Archived'}]);assert.equal(m.total,2);assert.equal(m.completed,1);assert.equal(m.in_progress,1);
});
